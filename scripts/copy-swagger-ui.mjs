import { copyFile, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const source = resolve(root, 'node_modules', 'swagger-ui-dist');
const destination = resolve(root, 'public', 'assets', 'swagger-ui');

await mkdir(destination, { recursive: true });
await Promise.all(
    ['swagger-ui.css', 'swagger-ui-bundle.js'].map((file) =>
        copyFile(resolve(source, file), resolve(destination, file)),
    ),
);
