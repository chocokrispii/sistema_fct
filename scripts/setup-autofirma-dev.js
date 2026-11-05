import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const envPath = resolve(root, '.env');
if (!existsSync(envPath)) {
    throw new Error('No existe .env. Copia .env.example a .env y vuelve a ejecutar este script.');
}

const password = randomBytes(24).toString('hex');
const command = [
    'set -eu',
    'directory=/var/lib/fct/storage/autofirma-dev',
    'mkdir -p "$directory"',
    'trap \'rm -f "$directory/temporary-key.pem"\' EXIT',
    'openssl req -x509 -newkey rsa:3072 -nodes -days 365 -keyout "$directory/temporary-key.pem" -out "$directory/signing-cert.pem" -subj "/CN=Sistema FCT Local Test Signer" -addext "keyUsage=critical,digitalSignature,keyCertSign,cRLSign" -addext "extendedKeyUsage=emailProtection"',
    'openssl pkcs12 -export -out "$directory/firmante.p12" -inkey "$directory/temporary-key.pem" -in "$directory/signing-cert.pem" -passout env:FCT_DEV_P12_PASSWORD',
    'mkdir -p "$directory/nssdb"',
    'if [ ! -f "$directory/nssdb/key4.db" ]; then certutil -N --empty-password -d "sql:$directory/nssdb"; fi',
    'if certutil -L -d "sql:$directory/nssdb" -n "Sistema FCT Local Test Signer" >/dev/null 2>&1; then certutil -D -d "sql:$directory/nssdb" -n "Sistema FCT Local Test Signer"; fi',
    'certutil -A -d "sql:$directory/nssdb" -n "Sistema FCT Local Test Signer" -t "CT,C,C" -i "$directory/signing-cert.pem"',
    'chmod 600 "$directory/firmante.p12" "$directory/signing-cert.pem"',
].join('; ');

execFileSync('docker', [
    'compose', 'exec', '-T', '-e', `FCT_DEV_P12_PASSWORD=${password}`,
    'app', 'sh', '-lc', command,
], { cwd: root, stdio: 'inherit' });

const php = [
    'require "vendor/autoload.php";',
    '$pdf = new \\setasign\\Fpdi\\Fpdi();',
    '$pdf->AddPage();',
    '$pdf->SetFont("Helvetica", "B", 16);',
    '$pdf->Cell(0, 12, "Convenio de prueba - Sistema FCT", 0, 1);',
    '$pdf->SetFont("Helvetica", "", 11);',
    '$pdf->MultiCell(0, 8, "Documento ficticio para probar la firma PAdES local. No tiene validez contractual ni debe utilizarse con datos reales.");',
    '$pdf->Output("/var/lib/fct/storage/autofirma-dev/convenio-prueba.pdf", "F");',
].join(' ');
execFileSync('docker', ['compose', 'exec', '-T', 'app', 'php', '-r', php], {
    cwd: root,
    stdio: 'inherit',
});
execFileSync('docker', [
    'compose', 'exec', '-T', 'app', 'sh', '-lc',
    'directory=/var/lib/fct/storage/autofirma-dev; chown -R www-data:www-data "$directory"; chmod 700 "$directory" "$directory/nssdb"; chmod 600 "$directory/firmante.p12" "$directory/signing-cert.pem" "$directory/convenio-prueba.pdf"',
], { cwd: root, stdio: 'inherit' });

const originalEnv = readFileSync(envPath, 'utf8');
const newline = originalEnv.includes('\r\n') ? '\r\n' : '\n';
const lines = originalEnv.split(/\r?\n/);
const values = {
    FCT_CONVENIO_SIGNATURE_MODE: 'mock',
    FCT_CONVENIO_MOCK_P12_PASSWORD: password,
    FCT_APP_URL: 'http://localhost:8080',
    FCT_MAIL_TRANSPORT: 'mailpit',
    FCT_MAIL_HOST: 'mailpit',
    FCT_MAIL_PORT: '1025',
    FCT_MAIL_ENCRYPTION: 'none',
    FCT_MAIL_FROM: 'noreply@example.test',
};
for (const [key, value] of Object.entries(values)) {
    const matcher = new RegExp(`^${key}=`);
    const index = lines.findIndex((line) => matcher.test(line));
    if (index >= 0) {
        lines[index] = `${key}=${value}`;
    } else {
        lines.push(`${key}=${value}`);
    }
}
writeFileSync(envPath, lines.join(newline), { mode: 0o600 });

console.log('Certificado PKCS#12, almacén NSS y PDF ficticio creados en el almacenamiento privado de Docker.');
console.log('Se configuró el modo mock en .env. Recrea el servicio app para cargar la contraseña generada: docker compose up -d --force-recreate app');
