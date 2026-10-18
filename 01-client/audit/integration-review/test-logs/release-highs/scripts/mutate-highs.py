# Targeted mutations for the five High fixes (N1-N5): apply one, run the named test file(s), restore.
# CAUGHT means at least one FAIL line or a non-zero exit. Files are always restored.
import sys, subprocess
root = sys.argv[1]
check_only = len(sys.argv) > 2 and sys.argv[2] == '--check'

def run(tree, kind, test):
    code = (f"require 'config.php'; require 'tests/guard.php'; slate_require_test_database(); "
            f"require 'tests/unit/harness.php'; require 'tests/{kind}/{test}.php'; exit(unit_summary());")
    p = subprocess.run(['php', '-r', code], cwd=f"{root}/{tree}", capture_output=True, text=True, timeout=900)
    lines = p.stdout.splitlines()
    fails = [l for l in lines if l.startswith('FAIL')]
    summ = [l for l in lines if l.startswith('# passed')]
    return p.returncode, fails, (summ[-1] if summ else 'NO SUMMARY (fatal?)')

C, L = '01-client', '02-licensing'
REFUSE = """        throw new InvalidArgumentException("The setting '{$key}' can't be changed through MCP. Change it from the admin UI.");"""
M = [
 ('N1: post content fallback emitted raw', C, 'src/Module/StudioBuilder/Render/Block/CoreRenderers/PostContentRenderer.php',
  ': RichTextSanitizer::sanitize($content);', ': $content;', [(C, 'unit', 'StudioBuilderSprint6ThemeBuilderTest')]),
 ('N2: settings.set accepts any other key', C, 'plugins/mcp-gateway/CoreMcpTools.php',
  REFUSE, '        return $value;', [(C, 'integration', 'McpSettingsAllowlistTest')]),
 ('N2: settings.get returns encrypted values', C, 'plugins/mcp-gateway/CoreMcpTools.php',
  " || str_starts_with((string) $v, 'enc:v1:') ? '[hidden]'", " ? '[hidden]'", [(C, 'integration', 'McpSettingsAllowlistTest')]),
 ('N2: audit redaction back to password|secret', C, 'plugins/mcp-gateway/McpGatewayAPI.php',
  "preg_match('/pass|secret|token|api_?key|private|credential|webhook|signing/i', $args['key']);",
  "preg_match('/password|secret/i', $args['key']);", [(C, 'integration', 'McpSettingsAllowlistTest')]),
 ('N2 (central): settings.set accepts any other key', L, 'plugins/mcp-gateway/CoreMcpTools.php',
  REFUSE, '        return $value;', [(L, 'integration', 'McpSettingsAllowlistTest')]),
 ('N3: backing page matched without the exact id check', C, 'plugins/studio-builder/admin/templates.php',
  "\n                            && isset($settings['template_id']) && (int) $settings['template_id'] === $templateId;", ';',
  [(C, 'integration', 'StudioTemplateDeleteTest')]),
 ('N3: unknown/system template not refused', C, 'plugins/studio-builder/admin/templates.php',
  "AND is_system = 0\", [$tenantId, $templateId])\n                : null;",
  "\", [$tenantId, $templateId])\n                : null;", [(C, 'integration', 'StudioTemplateDeleteTest')]),
 ('N4: resolver reads draft settings again', C, 'src/Module/StudioBuilder/Theme/ThemeTemplateResolver.php',
  'if ($draft === 0 || $draft === $published) {', 'if (true) {', [(C, 'unit', 'StudioBuilderSprint6ThemeBuilderTest')]),
 ('N5: per-action Studio permission skipped', C, 'plugins/studio-builder/admin/templates.php',
  'if (!StudioActor::fromCurrentSession()->can($requiredPerm)) {', 'if (false) {', [(C, 'integration', 'StudioAdminPermissionsTest')]),
 ('N5: unpublish accepts any page', C, 'plugins/studio-builder/admin/templates.php',
  'if (!$isTemplatePage) {', 'if (false) {', [(C, 'integration', 'StudioAdminPermissionsTest')]),
 ('N5: content page without edit permission', C, 'plugins/studio-builder/admin/content.php',
  "Auth::requirePerm('studio-builder.edit');", '', [(C, 'integration', 'StudioAdminPermissionsTest')]),
]

caught = 0
for name, tree, f, old, new, tests in M:
    p = f"{root}/{tree}/{f}"; orig = open(p).read()
    if orig.count(old) != 1:
        print(f"SKIPPED (pattern count {orig.count(old)}) - {name}"); continue
    if check_only:
        print(f"ok pattern - {name}"); continue
    open(p, 'w').write(orig.replace(old, new, 1))
    try:
        res = [run(*t) for t in tests]
    finally:
        open(p, 'w').write(orig)
    nf = sum(len(r[1]) for r in res); bad = any(r[0] != 0 for r in res)
    ok = nf > 0 or bad; caught += ok
    detail = '; '.join(f"{t[2]}: {r[2]}" for t, r in zip(tests, res))
    print(f"{'CAUGHT' if ok else 'SURVIVED'} ({nf}) - {name} | {detail}")
if not check_only:
    print(f"{caught}/{len(M)} caught")
