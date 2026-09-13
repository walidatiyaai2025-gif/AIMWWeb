from pathlib import Path

path = Path('variants/laravel-aiwmweb/backend/tests/Feature/PlatformServicesParityClosureTest.php')
text = path.read_text()
op = 'AIMW-PLAT-F6C1A04662'
if f"'{op}' => [" in text:
    raise SystemExit('contract already present')
needle = "    private const STRICT_TENANT_NEUTRAL_FOCUSED_SERVICE_OPERATIONS = [\n"
block = """        'AIMW-PLAT-F6C1A04662' => [
            'route_screen' => 'service:ApplicationPathService',
            'current_source' => 'src/AIWordPressManager.Infrastructure/Paths/ApplicationPathService.cs',
            'visible_control' => 'GetDatabasePath',
            'destination' => 'variants/laravel-aiwmweb/backend/config/database.php',
            'acceptance_test' => 'variants/laravel-aiwmweb/backend/tests/Feature/DatabasePathTerminalityTest.php',
            'evidence_path' => 'variants/laravel-aiwmweb/docs/closure-evidence/database-path-terminality.json',
            'signals' => [
                'operation:AIMW-PLAT-F6C1A04662',
                'service:ApplicationPathService',
                'member:GetDatabasePath',
                'test:variants/laravel-aiwmweb/backend/tests/Feature/DatabasePathTerminalityTest.php',
                'evidence:variants/laravel-aiwmweb/docs/closure-evidence/database-path-terminality.json',
            ],
        ],
"""
if text.count(needle) != 1:
    raise SystemExit('strict contract insertion anchor is not unique')
path.write_text(text.replace(needle, needle + block, 1))
