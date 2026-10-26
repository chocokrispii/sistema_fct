import { randomBytes } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { createServer } from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const projectName = `fct-test-${process.pid}-${randomBytes(4).toString('hex')}`;
const databaseName = `fct_test_${process.pid}_${Date.now()}`;
const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function freePort() {
    const server = createServer();
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    const address = server.address();
    await new Promise((resolve, reject) => {
        server.close((error) => error ? reject(error) : resolve());
    });
    if (!address || typeof address === 'string') {
        throw new Error('Could not allocate a local test port.');
    }
    return address.port;
}

const [appPort, dbPort, phpMyAdminPort, vitePort] = await Promise.all(
    Array.from({ length: 4 }, freePort),
);
const composeEnv = {
    ...process.env,
    APP_ENV: 'test',
    APP_PORT: String(appPort),
    COMPOSE_PROFILES: '',
    DB_PORT: String(dbPort),
    FCT_CLAMAV_ENABLED: 'false',
    FCT_DB_HOST: 'db',
    FCT_DB_NAME: databaseName,
    FCT_DB_PASS: `test-${randomBytes(24).toString('hex')}`,
    FCT_DB_USER: 'fct',
    FCT_TEST_DB_NAME: databaseName,
    MARIADB_ROOT_PASSWORD: `root-${randomBytes(24).toString('hex')}`,
    PMA_PORT: String(phpMyAdminPort),
    VITE_PORT: String(vitePort),
};

function compose(args, { capture = false, allowFailure = false } = {}) {
    const result = spawnSync(
        'docker',
        ['compose', '--project-name', projectName, ...args],
        {
            cwd: repositoryRoot,
            env: composeEnv,
            encoding: 'utf8',
            stdio: capture ? ['ignore', 'pipe', 'pipe'] : 'inherit',
        },
    );
    if (result.error) {
        throw result.error;
    }
    if (!allowFailure && result.status !== 0) {
        throw new Error(
            `docker compose ${args.join(' ')} failed with exit code ${result.status ?? 'unknown'}.\n${result.stderr ?? ''}`,
        );
    }
    return result;
}

function healthStatus(service) {
    const container = compose(['ps', '-q', service], { capture: true, allowFailure: true });
    const containerId = container.stdout.trim();
    if (!containerId) {
        return 'missing';
    }
    const status = spawnSync(
        'docker',
        ['inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}', containerId],
        { cwd: repositoryRoot, env: composeEnv, encoding: 'utf8' },
    );
    return status.status === 0 ? status.stdout.trim() : 'unknown';
}

async function waitForHealthyServices() {
    const required = ['db', 'app'];
    const deadline = Date.now() + 10 * 60 * 1000;
    while (Date.now() < deadline) {
        const statuses = Object.fromEntries(required.map((service) => [service, healthStatus(service)]));
        if (required.every((service) => statuses[service] === 'healthy')) {
            return;
        }
        if (Object.values(statuses).some((status) => ['unhealthy', 'exited', 'dead'].includes(status))) {
            compose(['logs', '--no-color', ...required], { allowFailure: true });
            throw new Error(`Test services failed health checks: ${JSON.stringify(statuses)}`);
        }
        await wait(2000);
    }
    compose(['logs', '--no-color', ...required], { allowFailure: true });
    throw new Error('Timed out waiting for isolated test services to become healthy.');
}

let failure = null;
try {
    compose(['up', '-d', '--build', 'app']);
    await waitForHealthyServices();

    const phpLint = "find . -type d \\( -name .git -o -name node_modules -o -name vendor \\) -prune -o -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l";
    compose(['exec', '-T', 'app', 'sh', '-lc', phpLint]);
    compose(['exec', '-T', '-e', `FCT_TEST_DB_NAME=${databaseName}`, 'app', 'php', 'tests/bootstrap_test_data.php']);
    compose(['exec', '-T', '-e', `FCT_TEST_DB_NAME=${databaseName}`, 'app', 'php', 'tests/run.php']);
    compose(['exec', '-T', '-e', `FCT_TEST_DB_NAME=${databaseName}`, 'app', 'php', 'tests/Feature/http_regression.php']);
    console.log('All static checks and integration tests passed.');
} catch (error) {
    failure = error;
} finally {
    const cleanup = compose(['down', '--volumes', '--remove-orphans'], { allowFailure: true });
    if (cleanup.status !== 0) {
        console.error(`Could not clean isolated Compose project ${projectName}.`);
        failure ??= new Error(`docker compose down failed with exit code ${cleanup.status ?? 'unknown'}.`);
    }
}

if (failure) {
    console.error(failure);
    process.exitCode = 1;
}
