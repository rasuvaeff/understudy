<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Bypass;

use Rasuvaeff\Understudy\Bypass\FileWrapper;
use Rasuvaeff\Understudy\Exception\BypassUnavailable;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Bypass\PassiveWrapper;
use Rasuvaeff\Understudy\Tests\Fixture\Suit;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * The decisions behind `Understudy::bypassFinals()`, taken in this process.
 *
 * What cannot be made here is a claim about load order: a class is read from
 * disk once, so "this class was opened before it loaded" needs a process of its
 * own and lives in `BypassFinalsIntegrationTest`. Every refusal and every
 * registration is a decision that happens before any class is read, and the
 * wrapper is taken down after each test, which is what `FileWrapperTest` does
 * with the same global.
 */
#[Test]
#[Covers(Understudy::class)]
#[Covers(FileWrapper::class)]
#[Covers(BypassUnavailable::class)]
final class BypassFinalsTest
{
    private const string NAMESPACE = 'Rasuvaeff\\Understudy\\Tests\\Fixture\\Bypass';

    #[AfterTest]
    public function tearDown(): void
    {
        FileWrapper::uninstall();
        FileWrapper::targetOnly([]);
    }

    public function noClassAsksForEveryClass(): void
    {
        Understudy::bypassFinals();

        Assert::true(FileWrapper::isInstalled());
        Assert::true(FileWrapper::covers('Any\\Class\\Whatever'));
        Assert::true(FileWrapper::covers('Global'));
    }

    public function aNamespacedClassIsNamedByNamespaceAndShortName(): void
    {
        Understudy::bypassFinals(self::NAMESPACE . '\\NeverLoadedGate');

        Assert::true(FileWrapper::isInstalled());
        Assert::true(FileWrapper::covers(self::NAMESPACE . '\\NeverLoadedGate'));
        Assert::false(FileWrapper::covers(self::NAMESPACE . '\\NeverLoaded'));
        Assert::false(FileWrapper::covers(self::NAMESPACE . '\\Gate'));
        Assert::false(FileWrapper::covers('Other\\NeverLoadedGate'));
        Assert::false(FileWrapper::covers('NeverLoadedGate'));
        Assert::false(FileWrapper::covers('Fixture\\Bypass\\NeverLoadedGate'));
    }

    public function aGlobalNamespaceClassIsNamedByItsShortNameAlone(): void
    {
        Understudy::bypassFinals('NeverLoadedGlobalGate');

        Assert::true(FileWrapper::covers('NeverLoadedGlobalGate'));
        Assert::false(FileWrapper::covers('Ns\\NeverLoadedGlobalGate'));
        Assert::false(FileWrapper::covers('LoadedGlobalGate'));
    }

    public function aSecondCallWidensTheFirstAndIsNotRefusedBecauseOfIt(): void
    {
        Understudy::bypassFinals(self::NAMESPACE . '\\NeverLoadedOne');
        Understudy::bypassFinals(self::NAMESPACE . '\\NeverLoadedTwo');

        Assert::true(FileWrapper::covers(self::NAMESPACE . '\\NeverLoadedOne'));
        Assert::true(FileWrapper::covers(self::NAMESPACE . '\\NeverLoadedTwo'));
    }

    public function askingForEveryClassAfterTheWrapperIsInstalledIsNotRefused(): void
    {
        Understudy::bypassFinals();
        Understudy::bypassFinals();

        Assert::true(FileWrapper::covers('Any\\Class\\Whatever'));
    }

    public function aLoadedClassIsRefusedAndNothingIsInstalled(): void
    {
        class_exists(Book::class);

        try {
            Understudy::bypassFinals(Book::class);
            Assert::fail('a loaded class must be refused');
        } catch (BypassUnavailable $refused) {
            Assert::string($refused->getMessage())->contains('`' . Book::class . '` is already loaded');
        }

        Assert::false(FileWrapper::isInstalled());
    }

    public function anEnumIsRefusedAndNothingIsInstalled(): void
    {
        enum_exists(Suit::class);

        try {
            Understudy::bypassFinals(Suit::class);
            Assert::fail('an enum must be refused');
        } catch (BypassUnavailable $refused) {
            Assert::string($refused->getMessage())->contains('`' . Suit::class . '` is an enum');
        }

        Assert::false(FileWrapper::isInstalled());
    }

    public function anInterfaceIsRefusedAndNothingIsInstalled(): void
    {
        interface_exists(BookRepository::class);

        try {
            Understudy::bypassFinals(BookRepository::class);
            Assert::fail('an interface must be refused');
        } catch (BypassUnavailable $refused) {
            Assert::string($refused->getMessage())->contains('`' . BookRepository::class . '` is an interface');
        }

        Assert::false(FileWrapper::isInstalled());
    }

    public function anotherUserSpaceWrapperOnFileIsRefusedForEveryShapeOfTheRequest(): void
    {
        // Everything the refusal needs is in memory before the other wrapper
        // takes `file://`, so reading it back is not what is being tested.
        class_exists(BypassUnavailable::class);

        foreach ([null, self::NAMESPACE . '\\NeverLoadedGate'] as $class) {
            PassiveWrapper::install();

            try {
                Understudy::bypassFinals($class);
                Assert::fail('another wrapper on `file://` must be refused');
            } catch (BypassUnavailable $refused) {
                Assert::string($refused->getMessage())->contains('(a user-space wrapper already owns `file://`)');
            } finally {
                stream_wrapper_restore('file');
            }

            Assert::false(FileWrapper::isInstalled());
        }
    }

    public function probingForAnotherWrapperLeavesNoStreamOpen(): void
    {
        $before = \count(get_resources('stream'));

        Understudy::bypassFinals(self::NAMESPACE . '\\NeverLoadedGate');

        Assert::same(\count(get_resources('stream')), $before);
    }
}
