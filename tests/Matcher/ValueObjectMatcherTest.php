<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Matcher;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;
use Rasuvaeff\Understudy\Matcher\InstanceOfType;
use Rasuvaeff\Understudy\Matcher\PropertyEquals;
use Rasuvaeff\Understudy\Matcher\StructurallyEqual;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\Suit;
use Rasuvaeff\Understudy\Tests\Fixture\Vo\Email;
use Rasuvaeff\Understudy\Tests\Fixture\Vo\Mailer;
use Rasuvaeff\Understudy\Tests\Fixture\Vo\Node;
use Rasuvaeff\Understudy\Tests\Support\MatcherGenerators;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * The value-object matchers: a typed `instanceOf()` predicate, `equalTo()` and
 * `whichProperty()`. Each is asked about every shape matching meets inside the
 * code under test, where none of them may throw.
 */
#[Test]
#[Covers(Arg::class)]
#[Covers(InstanceOfType::class)]
#[Covers(PropertyEquals::class)]
#[Covers(StructurallyEqual::class)]
final class ValueObjectMatcherTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function aTypedPredicateIsAskedOnlyOfAnInstance(): void
    {
        $asked = 0;
        $matcher = $this->matcher(Arg::instanceOf(Email::class, static function (Email $email) use (&$asked): bool {
            $asked++;

            return $email->to === 'a@b.c';
        }));

        Assert::true($matcher->matches(new Email('a@b.c')));
        Assert::false($matcher->matches(new Email('x@y.z')));
        Assert::false($matcher->matches(new Book()));
        Assert::false($matcher->matches('a@b.c'));
        Assert::same($asked, 2);
        Assert::same($matcher->describe(), 'instanceOf(' . Email::class . ', satisfies(…))');
    }

    public function withoutAPredicateItIsTheTypeAlone(): void
    {
        $matcher = $this->matcher(Arg::instanceOf(Email::class));

        Assert::true($matcher->matches(new Email('a@b.c')));
        Assert::same($matcher->describe(), 'instanceOf(' . Email::class . ')');
    }

    public function aTypedPredicateDispatchesWithoutATypeErrorForAnotherType(): void
    {
        $mailer = Understudy::for(Mailer::class);
        when(static fn() => $mailer->send(Arg::instanceOf(Email::class, static fn(Email $e): bool => $e->to === 'a@b.c')))
            ->returns(true);

        Assert::true($mailer->send(new Email('a@b.c')));
        Assert::false($mailer->send(new Book()));
    }

    public function equalToComparesValueObjectsByStructure(): void
    {
        $matcher = $this->matcher(Arg::equalTo(new Email('a@b.c', 'hi', 2)));

        Assert::true($matcher->matches(new Email('a@b.c', 'hi', 2)));
        Assert::false($matcher->matches(new Email('a@b.c', 'hi', 3)));
        Assert::false($matcher->matches(new Email('a@b.c', 'ho', 2)));
        Assert::false($matcher->matches(new Book('a@b.c')));
        Assert::false($matcher->matches(null));
        Assert::same($matcher->describe(), 'equalTo(' . Email::class . '#1 {to: \'a@b.c\', subject: \'hi\'})');
    }

    public function equalToIsStrictOnScalars(): void
    {
        Assert::false($this->matcher(Arg::equalTo(1))->matches('1'));
        Assert::false($this->matcher(Arg::equalTo(new Book('1')))->matches(new Book('01')));
        Assert::true($this->matcher(Arg::equalTo(['a' => 1, 'b' => [2]]))->matches(['b' => [2], 'a' => 1]));
        Assert::false($this->matcher(Arg::equalTo(['a' => 1]))->matches(['a' => 1, 'b' => 2]));
        Assert::false($this->matcher(Arg::equalTo(['a' => 1]))->matches(['b' => 1]));
        Assert::false($this->matcher(Arg::equalTo([1]))->matches(new Book()));
    }

    public function equalToComparesEnumsAndClosuresByIdentity(): void
    {
        $closure = static fn(): int => 1;

        Assert::true($this->matcher(Arg::equalTo(Suit::Hearts))->matches(Suit::Hearts));
        Assert::false($this->matcher(Arg::equalTo(Suit::Hearts))->matches(Suit::Spades));
        Assert::true($this->matcher(Arg::equalTo($closure))->matches($closure));
        Assert::false($this->matcher(Arg::equalTo($closure))->matches(static fn(): int => 1));
    }

    public function equalToComparesDatesByInstantAndZone(): void
    {
        $matcher = $this->matcher(Arg::equalTo(new \DateTimeImmutable('2026-01-01 10:00', new \DateTimeZone('UTC'))));

        Assert::true($matcher->matches(new \DateTimeImmutable('2026-01-01 10:00', new \DateTimeZone('UTC'))));
        Assert::false($matcher->matches(new \DateTimeImmutable('2026-01-01 11:00', new \DateTimeZone('+01:00'))));
        Assert::false($matcher->matches(new \DateTimeImmutable('2026-01-01 10:01', new \DateTimeZone('UTC'))));
    }

    public function equalToSurvivesCycles(): void
    {
        $a = new Node('a');
        $a->next = $a;
        $b = new Node('a');
        $b->next = $b;
        $c = new Node('c');
        $c->next = $c;

        Assert::true($this->matcher(Arg::equalTo($a))->matches($b));
        Assert::false($this->matcher(Arg::equalTo($a))->matches($c));

        $loop = [1];
        $loop[] = &$loop;
        $other = [1];
        $other[] = &$other;

        Assert::false($this->matcher(Arg::equalTo($loop))->matches($other));
    }

    public function equalToRefusesACaptor(): void
    {
        Expect::exception(InvalidCallSpecification::class);

        Arg::equalTo(Arg::captor()->capture());
    }

    #[Property(runs: 200)]
    public function equalToIsReflexiveOverCopies(mixed $value): void
    {
        $copy = unserialize(serialize($value));

        Assert::true($this->matcher(Arg::equalTo($value))->matches($copy));
        Assert::true($this->matcher(Arg::equalTo(new Book(serialize($value))))->matches(new Book(serialize($copy))));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function equalToIsReflexiveOverCopiesGenerators(): array
    {
        return ['value' => MatcherGenerators::anyValue()];
    }

    #[Property(runs: 300)]
    public function equalToIsSymmetric(mixed $left, mixed $right): void
    {
        Assert::same(
            $this->matcher(Arg::equalTo($left))->matches($right),
            $this->matcher(Arg::equalTo($right))->matches($left),
        );
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function equalToIsSymmetricGenerators(): array
    {
        $small = Gen::frequency([
            [3, Gen::intBetween(0, 2)],
            [1, Gen::elements(['0', '1', 0.0, null, true])],
            [2, Gen::arrayOf(Gen::intBetween(0, 1), 0, 2)],
        ]);

        return ['left' => $small, 'right' => $small];
    }

    public function whichPropertyReadsAPublicProperty(): void
    {
        $matcher = $this->matcher(Arg::whichProperty('to', 'a@b.c'));

        Assert::true($matcher->matches(new Email('a@b.c')));
        Assert::false($matcher->matches(new Email('x@y.z')));
        Assert::same($matcher->describe(), "whichProperty(to, 'a@b.c')");
    }

    public function whichPropertyIsAMismatchForAnythingItMayNotRead(): void
    {
        Assert::false($this->matcher(Arg::whichProperty('priority', 0))->matches(new Email('a@b.c')));
        Assert::false($this->matcher(Arg::whichProperty('missing', null))->matches(new Email('a@b.c')));
        Assert::false($this->matcher(Arg::whichProperty('to', 'a@b.c'))->matches('a@b.c'));
        Assert::false($this->matcher(Arg::whichProperty('count', null))->matches(new Node('n')));
        Assert::false($this->matcher(Arg::whichProperty('unset', null))->matches(new Node('n')));
        Assert::true($this->matcher(Arg::whichProperty('next', null))->matches(new Node('n')));
    }

    private function matcher(mixed $matcher): ArgumentMatcher
    {
        \assert($matcher instanceof ArgumentMatcher);

        return $matcher;
    }
}
