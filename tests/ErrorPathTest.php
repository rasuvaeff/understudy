<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Exception\CannotWire;
use Rasuvaeff\Understudy\Exception\NoDefaultValue;
use Rasuvaeff\Understudy\Exception\OutcomeUnavailable;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Librarian;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Understudy\Wiring\Wire;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * The refusals and the diagnostics — the paths a user only meets when
 * something is already wrong, and where a bad message costs more than
 * anywhere else.
 *
 * They are gathered here rather than scattered because each is one call and
 * one assertion on the text: separately they are too small to earn a file, and
 * together they are the difference between "understudy said no" and
 * "understudy said no, and here is what to do instead".
 *
 * @internal
 */
#[Test]
#[Covers(Understudy::class)]
#[Covers(Wire::class)]
#[Covers(CannotWire::class)]
#[Covers(Invocation::class)]
#[Covers(OutcomeUnavailable::class)]
final class ErrorPathTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- strict stubs ---------------------------------------------------------

    public function anUnusedStubUnderStrictStubsSaysWhatToDoAboutIt(): void
    {
        $double = Understudy::for(BookRepository::class);

        when(static fn() => $double->find(7))->returns(new Book('x'));

        try {
            Understudy::verifyAll(strictStubs: true);

            Assert::fail('Expected the unused stub to be reported');
        } catch (VerificationFailed $failure) {
            Assert::string($failure->getMessage())
                ->contains('has a stub for `find(7)` that was never used')
                ->contains('Remove it, or drop strictStubs if the call is genuinely optional');
        }
    }

    public function aStubThatWasUsedIsFineUnderStrictStubs(): void
    {
        $double = Understudy::for(BookRepository::class);

        when(static fn() => $double->find(7))->returns(new Book('x'));
        $double->find(7);

        Understudy::verifyAll(strictStubs: true);

        Assert::true(actual: true);
    }

    // --- the loose-default registry -------------------------------------------

    public function aRegisteredFactoryAnswersWhereTheTableWouldNot(): void
    {
        $book = new Book('registered');
        Understudy::defaults(Book::class, static fn(): Book => $book);

        $double = Understudy::for(Librarian::class);

        Assert::same($double->pick(), $book);
    }

    public function aRegistrationDoesNotOutliveTheContext(): void
    {
        Understudy::defaults(Book::class, static fn(): Book => new Book('registered'));
        Understudy::reset();

        // Back to having no answer at all: Book is final, so the engine
        // cannot invent one either, and the registration is genuinely gone
        // rather than merely shadowed.
        try {
            Understudy::for(Librarian::class)->pick();

            Assert::fail('Expected the registration to be gone with the context');
        } catch (NoDefaultValue $failure) {
            Assert::string($failure->getMessage())->contains('no safe default');
        }
    }

    public function aNullableReturnAnswersNullWithoutConsultingTheRegistry(): void
    {
        // Worth pinning rather than leaving to be rediscovered: `?Book` is
        // answered by `null` before the loose-default registry is asked, so a
        // registration for `Book` has no effect on a method declared nullable.
        Understudy::defaults(Book::class, static fn(): Book => new Book('registered'));

        Assert::null(Understudy::for(BookRepository::class)->find(1));
    }

    // --- wire() refusals ------------------------------------------------------

    public function wiringATraitIsRefusedByName(): void
    {
        try {
            Understudy::wire(SomeTrait::class);

            Assert::fail('Expected a trait to be refused');
        } catch (CannotWire $failure) {
            Assert::string($failure->getMessage())->contains('it is a trait');
        }
    }

    public function anOverrideForAConstructorlessClassNamesWhatIsKnown(): void
    {
        try {
            Understudy::wire(NoConstructor::class, ['whatever' => 1]);

            Assert::fail('Expected the override to be refused');
        } catch (CannotWire $failure) {
            Assert::string($failure->getMessage())
                ->contains('whatever')
                ->contains('nothing — it has no constructor');
        }
    }

    public function aResourceParameterIsRefusedWithTheReasonItCannotBeDecided(): void
    {
        $handle = fopen('php://memory', 'r');

        try {
            Understudy::wire(TakesAnything::class, ['value' => $handle]);

            Assert::fail('Expected a resource to be refused');
        } catch (CannotWire $failure) {
            Assert::string($failure->getMessage())
                ->contains('a resource cannot be passed as a constructor argument');
        } finally {
            \is_resource($handle) && fclose($handle);
        }
    }

    public function aUnionTypedParameterAcceptsAnyBranchAndRejectsTheRest(): void
    {
        $wired = Understudy::wire(TakesAUnion::class, ['value' => 'a string']);
        Assert::instanceOf($wired['sut'], TakesAUnion::class);

        $wired = Understudy::wire(TakesAUnion::class, ['value' => 42]);
        Assert::instanceOf($wired['sut'], TakesAUnion::class);

        try {
            Understudy::wire(TakesAUnion::class, ['value' => 1.5]);

            Assert::fail('Expected a float to be refused for int|string');
        } catch (CannotWire $failure) {
            Assert::string($failure->getMessage())->contains('value');
        }
    }

    // --- reading an outcome that is not there ---------------------------------

    public function askingForAReturnedValueOfACallThatThrewSaysSo(): void
    {
        $double = Understudy::for(BookRepository::class);

        when(static fn() => $double->find(7))->throws(new \RuntimeException('nope'));

        try {
            $double->find(7);
        } catch (\RuntimeException) {
            // expected
        }

        $calls = Understudy::calls(static fn() => $double->find(7));
        Assert::same(count($calls), 1);
        Assert::true($calls[0]->didThrow());

        try {
            $calls[0]->returned();

            Assert::fail('Expected reading a return value off a throwing call to be refused');
        } catch (OutcomeUnavailable $failure) {
            Assert::string($failure->getMessage())->contains('find');
        }
    }

    public function argsAfterIsNullForAMethodWithoutReferenceParameters(): void
    {
        $double = Understudy::for(BookRepository::class);
        $double->find(7);

        $calls = Understudy::calls(static fn() => $double->find(7));

        Assert::null($calls[0]->argsAfter());
    }
}

trait SomeTrait
{
    public function anything(): void {}
}

final class NoConstructor
{
    public function value(): int
    {
        return 1;
    }
}

final class TakesAnything
{
    public function __construct(public mixed $value) {}
}

final class TakesAUnion
{
    public function __construct(public int|string $value) {}
}
