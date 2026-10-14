<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A static guard for the two Alpine pages (Planning, My Department): every function a template calls from an event
 * handler (@click, @change, @input, ...) must be defined in that page's script. PHPUnit cannot run Alpine, so a typo'd
 * handler name (a click that silently does nothing) would otherwise only show up in a browser.
 */
class AlpineHandlersTest extends TestCase
{
    /** names that are fine to call from a template without being part of the component */
    private const GLOBALS = ['confirm', 'alert', 'parseInt', 'Number', 'String', 'Math', 'Date', 'Array', 'Object', 'Boolean', 'JSON', 'setTimeout', 'requestAnimationFrame'];

    private function viewsIn(string $dir): string
    {
        $all = '';
        foreach (glob(resource_path("views/$dir/*.blade.php")) as $f) {
            $all .= file_get_contents($f)."\n";
        }
        foreach (glob(resource_path("views/$dir/partials/*.blade.php")) as $f) {
            if (! str_ends_with($f, '_script.blade.php')) {
                $all .= file_get_contents($f)."\n";
            }
        }

        return $all;
    }

    /** @return list<string> function names the templates call from event handlers */
    private function handlerCalls(string $templates): array
    {
        preg_match_all('/\s@(?:click|change|input|keydown|keyup|dragstart|dragover|drop|submit|focus|blur)[\w.:\-]*="([^"]*)"/', $templates, $m);
        $calls = [];
        foreach ($m[1] as $expr) {
            // identifiers followed by "(" that are not a method on something (.foo( ) nor an Alpine magic ($foo()
            preg_match_all('/(?<![\w.$])([A-Za-z_]\w*)\s*\(/', $expr, $c);
            $calls = array_merge($calls, $c[1]);
        }

        return array_values(array_unique(array_diff($calls, self::GLOBALS, ['if', 'function', 'return', 'typeof'])));
    }

    /** @return list<string> methods defined on the Alpine component */
    private function definedIn(string $scriptFile): array
    {
        preg_match_all('/^\s{8,}(?:async\s+)?(?:get\s+)?([A-Za-z_]\w*)\s*\([^)]*\)\s*\{/m', file_get_contents($scriptFile), $m);

        return array_unique($m[1]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_every_handler_called_by_the_template_exists(string $viewDir, string $script): void
    {
        $missing = array_values(array_diff(
            $this->handlerCalls($this->viewsIn($viewDir)),
            $this->definedIn(resource_path($script)),
        ));

        $this->assertSame([], $missing, "handlers used in resources/views/$viewDir but not defined in $script");
    }

    /**
     * `:disabled="item.busy"` on an item that has no `busy` yet binds `undefined`, which Alpine renders as a DISABLED button
     * (the project modal's "รับงาน" could not be clicked). Flags must be coerced: `:disabled="!!item.busy"`.
     */
    public function test_disabled_bindings_on_optional_flags_are_coerced(): void
    {
        $bad = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if ($file->isFile() && preg_match('/:disabled="(?!!!)[\w.]*\.busy"/', file_get_contents($file->getPathname()))) {
                $bad[] = $file->getPathname();
            }
        }

        $this->assertSame([], $bad, 'use :disabled="!!x.busy" for flags that may be undefined');
    }

    public static function pages(): array
    {
        return [
            'planning board' => ['planning', 'js/planning-board.js'],
            'my department' => ['my-department', 'views/my-department/partials/_script.blade.php'],
        ];
    }
}
