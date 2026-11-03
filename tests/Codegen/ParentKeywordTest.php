<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Codegen\TypeRenderer;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ParentReturnBase;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ParentReturnChild;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The `parent` keyword as Reflection reports it on the engines that do not
 * resolve it themselves (8.3 and 8.4; 8.5 hands over the class name).
 *
 * On 8.5 the keyword never reaches the resolution, so a test through
 * `Understudy::for()` proves nothing about it there. The resolution is driven
 * directly, with the keyword handed in: it is the code that runs on the engines
 * that still need it, and it has to be checked on the one that does not.
 */
#[Test]
#[Covers(TypeRenderer::class)]
#[Covers(TargetUnifier::class)]
final class ParentKeywordTest
{
    #[DataProvider('renderedTypeProvider')]
    public function aRenderedTypeIsResolvedAgainstTheDeclaringClass(string $rendered, ?string $declaring, string $expected): void
    {
        $resolve = new \ReflectionMethod(TypeRenderer::class, 'resolveParent');

        Assert::same(
            $resolve->invoke(null, $rendered, $declaring === null ? null : new \ReflectionClass($declaring)),
            $expected,
        );
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function renderedTypeProvider(): iterable
    {
        $base = '\\' . ParentReturnBase::class;

        yield 'the bare keyword' => ['parent', ParentReturnChild::class, $base];
        yield 'the keyword in any case' => ['PARENT', ParentReturnChild::class, $base];
        yield 'a nullable keyword' => ['?parent', ParentReturnChild::class, '?' . $base];
        yield 'a keyword inside a union' => ['parent|null', ParentReturnChild::class, $base . '|null'];
        yield 'a keyword inside an intersection' => ['parent&\\Countable', ParentReturnChild::class, $base . '&\\Countable'];
        yield 'a qualified class named Parent' => ['\\App\\Parent', ParentReturnChild::class, '\\App\\Parent'];
        yield 'a namespace named Parent' => ['Parent\\Foo', ParentReturnChild::class, 'Parent\\Foo'];
        yield 'a longer word that starts with it' => ['Parented', ParentReturnChild::class, 'Parented'];
        yield 'a type without the keyword' => ['int', ParentReturnChild::class, 'int'];
        yield 'a class without a parent keeps the keyword' => ['parent', ParentReturnBase::class, 'parent'];
        yield 'no declaring class keeps the keyword' => ['parent', null, 'parent'];
    }

    public function theUnifierResolvesTheKeywordForAClassWithAParent(): void
    {
        $atom = new \ReflectionMethod(TargetUnifier::class, 'returnTypeAtom');
        $keyword = new class extends \ReflectionNamedType {
            #[\Override]
            public function getName(): string
            {
                return 'parent';
            }

            #[\Override]
            public function isBuiltin(): bool
            {
                return false;
            }
        };

        Assert::same(
            $atom->invoke(null, $keyword, new \ReflectionClass(ParentReturnChild::class)),
            '\\' . ParentReturnBase::class,
        );
        Assert::same($atom->invoke(null, $keyword, new \ReflectionClass(ParentReturnBase::class)), 'parent');
    }
}
