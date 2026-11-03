<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\Blueprint;
use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Shapes the main unifier suite reaches only indirectly: two intersection
 * returns that overlap through inheritance, and the helper that splits a
 * rendered union.
 */
#[Test]
#[Covers(TargetUnifier::class)]
#[Covers(DoubleFactory::class)]
#[Covers(Blueprint::class)]
final class TargetUnifierShapesTest
{
    private const string NAMESPACE = 'Rasuvaeff\\Understudy\\Tests\\Codegen\\Overlap';

    public function twoIntersectionReturnsThatOverlapThroughInheritanceCollapseToTheNarrowest(): void
    {
        $namespace = self::NAMESPACE;

        if (!interface_exists($namespace . '\\Left', autoload: false)) {
            eval(
                'namespace ' . $namespace . ';'
                . ' interface Base {} interface Child extends Base {} interface Other {} interface Third {}'
                . ' interface Left { public function make(): Child&Other; }'
                . ' interface Right { public function make(): Base&Third; }'
            );
        }

        $double = Understudy::for($namespace . '\\Left', $namespace . '\\Right');

        Assert::instanceOf($double, $namespace . '\\Left');
        Assert::instanceOf($double, $namespace . '\\Right');


        $return = (new \ReflectionMethod($double, 'make'))->getReturnType();

        Assert::instanceOf($return, \ReflectionIntersectionType::class);
        $names = array_map(static fn(\ReflectionNamedType $type): string => $type->getName(), $return->getTypes());
        sort($names);

        Assert::same($names, [
            $namespace . '\\Child',
            $namespace . '\\Other',
            $namespace . '\\Third',
        ]);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('unionProvider')]
    public function aRenderedUnionIsSplitKeepingGroupsIntact(string $rendered, array $expected): void
    {
        Assert::same((new \ReflectionMethod(TargetUnifier::class, 'splitUnion'))->invoke(null, $rendered), $expected);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function unionProvider(): iterable
    {
        yield 'a nullable shorthand' => ['?Foo', ['Foo', 'null']];
        yield 'a plain union' => ['int|string', ['int', 'string']];
        yield 'a DNF group with null' => ['(A&B)|null', ['(A&B)', 'null']];
        yield 'two DNF groups' => ['(A&B)|(C&D)', ['(A&B)', '(C&D)']];
        yield 'a single type' => ['Foo', ['Foo']];
        yield 'empty parts are dropped' => ['int||string', ['int', 'string']];
    }
}
