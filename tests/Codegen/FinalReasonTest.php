<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Bypass\FileWrapper;
use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\FinalLedger;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * A `final` that is still standing is explained in one of four ways, and the
 * explanation has to match the situation: telling a reader "bypass is not
 * enabled" when it is sends them to fix what is already right.
 *
 * The wrapper is installed and taken down inside each test, the way
 * `FileWrapperTest` does. What these tests cannot say is that bypass really
 * works — that needs a process per claim and lives in the integration suite.
 */
#[Test]
#[Covers(DoubleFactory::class)]
#[Covers(FileWrapper::class)]
#[Covers(UnsupportedTarget::class)]
final class FinalReasonTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        FileWrapper::uninstall();
        FileWrapper::targetOnly([]);
        Understudy::reset();
    }

    public function bypassEnabledForOtherClassesNamesTheOmission(): void
    {
        Understudy::bypassFinals('Elsewhere\\NeverLoadedGate');

        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . FinalLedger::class . "`: the class is final, and bypass is enabled for other classes but not this one.\n"
            . "- Name it too: Understudy::bypassFinals(FinalLedger::class)\n"
            . "- Or ask for every class with Understudy::bypassFinals(), before any of them loads.\n"
            . $this->recipe(),
        );

        Understudy::for(FinalLedger::class);
    }

    public function aClassBypassWasAskedForButAlreadyLoadedNamesItsFile(): void
    {
        Understudy::bypassFinals();
        $file = (new \ReflectionClass(FinalLedger::class))->getFileName();

        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . FinalLedger::class . '`: '
            . 'the class is final, and bypass was asked for it but could not reach it: '
            . 'it was already loaded, from `' . $file . "`, before the wrapper was installed.\n"
            . $this->unreachable(),
        );

        Understudy::for(FinalLedger::class);
    }

    public function aClassWithoutASourceFileSaysSo(): void
    {
        Understudy::bypassFinals();

        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `Closure`: '
            . "the class is final, and bypass was asked for it but could not reach it: it has no source file of its own.\n"
            . $this->unreachable('Closure'),
        );

        Understudy::for(\Closure::class);
    }

    public function aClassReadOutOfAPharSaysSo(): void
    {
        FileWrapper::install(null);

        $phar = new class (FinalLedger::class) extends \ReflectionClass {
            public function getFileName(): string
            {
                return 'phar:///tmp/gates.phar/Gate.php';
            }
        };

        $reason = (new \ReflectionMethod(DoubleFactory::class, 'whyFinalStillStands'))
            ->invoke(null, FinalLedger::class, $phar);

        Assert::same(
            $reason,
            'the class is final, and bypass was asked for it but could not reach it: '
            . "it was read out of a PHAR, and `phar://` is not `file://`.\n"
            . $this->unreachable(),
        );
    }

    public function anAnonymousClassHasNoNameToExtendBy(): void
    {
        $anonymous = new class {};

        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . $anonymous::class . '`: '
            . 'an anonymous class has no name to extend by. Give it one, or double the interface it implements.',
        );

        Understudy::for($anonymous::class);
    }

    private function unreachable(string $short = 'FinalLedger'): string
    {
        return "- Bypass rewrites a class as `file://` hands it to PHP. A source that arrives another way\n"
            . "  — out of a PHAR, through an OPcache preload, from eval(), or past another wrapper that\n"
            . "  owns `file://` — is out of reach whatever bypass was asked for.\n"
            . $this->recipe($short);
    }

    private function recipe(string $short = 'FinalLedger'): string
    {
        return "- Preferred: if it implements an interface, double the interface.\n"
            . "- If it is a value object, prefer a real instance.\n"
            . "- If it is a concrete dependency you cannot change, enable bypass before the class is\n"
            . '  first loaded: Understudy::bypassFinals(' . $short . "::class)\n"
            . '- Introducing an interface remains the cleanest long-term fix.';
    }
}
