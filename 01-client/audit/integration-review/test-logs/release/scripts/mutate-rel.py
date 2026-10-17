# Targeted mutation tests: apply one mutation, run the named test file(s), restore.
# CAUGHT means at least one FAIL line or a non-zero exit. Files are always restored.
import sys, subprocess
root = sys.argv[1]
def run(tree, kind, test):
    code = (f"require 'config.php'; require 'tests/guard.php'; slate_require_test_database(); "
            f"require 'tests/unit/harness.php'; require 'tests/{kind}/{test}.php'; exit(unit_summary());")
    p = subprocess.run(['php', '-r', code], cwd=f"{root}/{tree}", capture_output=True, text=True, timeout=900)
    lines = p.stdout.splitlines()
    fails = [l for l in lines if l.startswith('FAIL')]
    summ = [l for l in lines if l.startswith('# passed')]
    return p.returncode, fails, (summ[-1] if summ else 'NO SUMMARY (fatal?)')
C, L = '01-client', '02-licensing'
M = [
 ('AI vet: blocked-range check removed', C, 'plugins/mcp-gateway/AiProviderClient.php',
  "FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)\n                || self::inBlockedRange($ip)) {",
  "FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {", [(C,'unit','AiProviderClientUrlVettingTest')]),
 ('SMTP sealSecrets keeps plaintext on failure', C, 'includes/SmtpOAuth.php',
  "                } catch (\\Throwable $e) {\n                    return null;\n                }",
  "                } catch (\\Throwable $e) {\n                    $out[$k] = $v;\n                }", [(C,'integration','SmtpOAuthSealSecretsTest')]),
 ('OAuth callback ignores seal failure', C, 'admin/oauth_callback.php', "if ($sealed === null) {", "if (false && $sealed === null) {",
  [(C,'integration','SmtpOAuthAccessTokenEncryptionTest')]),
 ('Seal helper returns partial result on failure', C, 'includes/helpers.php',
  "            } catch (\\Throwable $e) {\n                return null;", "            } catch (\\Throwable $e) {\n                continue;",
  [(C,'unit','SealPostedSecretsTest'), (C,'integration','SettingsEncryptFirstTest')]),
 ('Stripe Disconnect skips the mode slots', C, 'plugins/stripe-payment/admin/settings.php',
  "        foreach (['test', 'live'] as $m) {", "        foreach ([] as $m) {", [(C,'integration','StripeWriteOnlySecretTest')]),
 ('Rekey: unreadable values no longer stop the run', C, 'bin/rekey-secrets.php', "if ($unreadable) {", "if (false) {",
  [(C,'integration','RekeySecretsCliTest')]),
 ('Rekey: writes not in a transaction', C, 'bin/rekey-secrets.php',
  "    $pdo->beginTransaction();\n", "", [(C,'integration','RekeySecretsCliTest')]),
 ('Rekey: restore skips the secret check', C, 'bin/rekey-secrets.php',
  "$id <= 0 || slate_reencrypt_envelope($env, $new, $new) === null) {", "$id <= 0) {", [(C,'integration','RekeySecretsCliTest')]),
 ('Rekey: non-InnoDB tables accepted', C, 'bin/rekey-secrets.php',
  "if ($nt = $nonTransactional($tables)) {\n    fwrite(STDERR, 'Stopped: these tables", "if (false) {\n    fwrite(STDERR, 'Stopped: these tables",
  [(C,'integration','RekeySecretsCliTest')]),
 ('Client licence guard: APP_ENV bypass on web', C, 'includes/license_guard.php',
  "(PHP_SAPI === 'cli' && env('APP_ENV') === 'testing')", "(env('APP_ENV') === 'testing')", [(C,'integration','AppEnvTestingBypassTest')]),
 ('Client ModuleGuard: APP_ENV bypass on web', C, 'src/Services/Licensing/ModuleGuard.php',
  "return \\PHP_SAPI === 'cli' && \\function_exists('env')", "return \\function_exists('env')", [(C,'integration','AppEnvTestingBypassTest')]),
 ('Central ModuleGuard: APP_ENV bypass on web', L, 'src/Services/Licensing/ModuleGuard.php',
  "return \\PHP_SAPI === 'cli' && \\function_exists('env')", "return \\function_exists('env')", [(L,'integration','CentralModuleGuardTest')]),
 ('Central ModuleGuard allows() always true', L, 'src/Services/Licensing/ModuleGuard.php',
  "        if (self::isBypassed()) return true;\n        return self::isEntitled($moduleKey);", "        return true;",
  [(L,'integration','CentralModuleGuardTest')]),
 ('assignPlan accepts inactive plans', L, 'src/Services/Tenancy/TenantService.php',
  "if (!(int) $plan['is_active']) {", "if (false) {", [(L,'integration','TenantPlanAdminTest'), (L,'integration','CentralTenantPlanCliTest')]),
 ('Tenants page without platform-admin gate', L, 'admin/tenants.php', "Auth::requirePlatformAdmin();", "",
  [(L,'integration','TenantPlanAdminTest')]),
]
caught = 0
for name, tree, f, old, new, tests in M:
    p = f"{root}/{tree}/{f}"; orig = open(p).read()
    if orig.count(old) != 1:
        print(f"SKIPPED (pattern count {orig.count(old)}) - {name}"); continue
    open(p, 'w').write(orig.replace(old, new, 1))
    try:
        res = [run(*t) for t in tests]
    finally:
        open(p, 'w').write(orig)
    nf = sum(len(r[1]) for r in res); bad = any(r[0] != 0 for r in res)
    ok = nf > 0 or bad; caught += ok
    detail = '; '.join(f"{t[2]}: {r[2]}" for t, r in zip(tests, res))
    print(f"{'CAUGHT' if ok else 'SURVIVED'} ({nf}) - {name} | {detail}")
print(f"{caught}/{len(M)} caught")
