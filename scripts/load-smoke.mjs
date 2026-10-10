import { performance } from 'node:perf_hooks';
import { writeFileSync } from 'node:fs';
import path from 'node:path';

const base = 'http://127.0.0.1:8088';
const concurrency = Number(process.argv[2] ?? 50);
const rounds = Number(process.argv[3] ?? 20);
if (
    !Number.isInteger(concurrency) ||
    concurrency < 1 ||
    concurrency > 100 ||
    !Number.isInteger(rounds) ||
    rounds < 1 ||
    rounds > 100
)
    throw new Error('DEV smoke limits: concurrency 1–100, rounds 1–100.');

const guards = [];
for (const [url, expected] of [
    ['/.env', 403],
    ['/_protected_media/not-a-real-file.pdf', 404],
    ['/auth/thaid/callback?code=synthetic&pid=0000000000001', 404],
]) {
    const response = await fetch(base + url, { redirect: 'manual' });
    await response.arrayBuffer();
    guards.push({ url: url.split('?')[0], expected, actual: response.status });
}
const csrf = await fetch(base + '/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ username: '0000000000001', password: 'synthetic-invalid-password' }),
    redirect: 'manual',
});
await csrf.arrayBuffer();
guards.push({ url: 'POST /login without CSRF', expected: 419, actual: csrf.status });

const samples = [];
const errors = [];
const start = performance.now();
await Promise.all(
    Array.from({ length: concurrency }, async (_, worker) => {
        for (let round = 0; round < rounds; round++) {
            const route = (worker + round) % 2 ? '/login' : '/up';
            const began = performance.now();
            try {
                const response = await fetch(base + route, {
                    redirect: 'manual',
                    signal: AbortSignal.timeout(30000),
                });
                await response.arrayBuffer();
                samples.push(performance.now() - began);
                if (response.status !== 200) errors.push({ route, status: response.status });
            } catch (error) {
                errors.push({ route, error: error.name });
            }
        }
    }),
);
samples.sort((a, b) => a - b);
const percentile = (p) =>
    Math.round(
        (samples[Math.min(samples.length - 1, Math.ceil(samples.length * p) - 1)] ?? 0) * 100,
    ) / 100;
const elapsed = (performance.now() - start) / 1000;
const result = {
    ok: errors.length === 0 && guards.every((guard) => guard.expected === guard.actual),
    scope: 'DEV anonymous login/availability HTTP smoke; no exam submits, authenticated load or video bandwidth',
    acceptance_target: 'provisional; requires organization confirmation and target-server rerun',
    concurrency,
    requests: concurrency * rounds,
    errors,
    guards,
    elapsed_seconds: Math.round(elapsed * 100) / 100,
    requests_per_second: Math.round((samples.length / elapsed) * 100) / 100,
    p50_ms: percentile(0.5),
    p95_ms: percentile(0.95),
    p99_ms: percentile(0.99),
};
writeFileSync(path.resolve('.cache/load-smoke.json'), JSON.stringify(result, null, 2));
console.log(JSON.stringify(result, null, 2));
if (!result.ok) process.exitCode = 1;
