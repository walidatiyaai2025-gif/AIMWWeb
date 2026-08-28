<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PintRouteDiagnosticTest extends TestCase
{
    public function test_prints_exact_pint_patch_for_route_file(): void
    {
        $pint = new Process([base_path('vendor/bin/pint'), base_path('routes/web.php')], base_path());
        $pint->setTimeout(60);
        $pint->run();

        $diff = new Process(['git', 'diff', '--', 'routes/web.php'], base_path());
        $diff->run();

        fwrite(STDERR, "\nPINT_ROUTE_PATCH_BEGIN\n".$diff->getOutput()."PINT_ROUTE_PATCH_END\n");

        $this->fail('Temporary diagnostic: copy the Pint patch above, then delete this test.');
    }
}
