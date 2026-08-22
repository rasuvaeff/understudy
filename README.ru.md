# rasuvaeff/understudy

> **Пре-релиз.** API не стабилен до `v0.1.0`. Что уже готово — в
> [CHANGELOG.md](CHANGELOG.md).

Библиотека тестовых дублей для PHP, где настраиваемый вызов — **настоящий
вызов**:

```php
when(fn () => $repository->find(123))->returns($book);
```

Никаких строк с именами методов: рефакторинг и навигация IDE работают без
плагина, а опечатка в имени метода невозможна. На самом дубле тоже нет
служебных методов — каждый из них отнимал бы имя у дублируемого контракта.

> Пользуетесь AI-ассистентом? [llms.txt](llms.txt) — компактный справочник по
> API, написанный для него.

## Зачем ещё одна

| | Understudy | Mockery / PHPUnit / double |
|---|---|---|
| Как задаётся вызов | настоящим вызовом в замыкании | строкой с именем метода |
| Служебные члены на дубле | нет | `shouldReceive`, `expects`, `allows`, … |
| Тестовый раннер | любой (тонкие адаптеры) | привязка к PHPUnit/Pest либо никакой |
| Fibers | свой контекст на файбер | общее статическое состояние |

Форма «вызов в замыкании» пришла из [MockK](https://mockk.io) (Kotlin),
[FakeItEasy](https://fakeiteasy.github.io) и [moq](https://github.com/moq/moq)
(C#), [mocktail](https://pub.dev/packages/mocktail) (Dart). В PHP её не было
ни у кого.

## Требования

- PHP 8.3 – 8.5
- `ext-mbstring`

Больше никаких runtime-зависимостей.

## Установка

```bash
composer require --dev rasuvaeff/understudy
```

## Использование

### Создание дубля

```php
use Rasuvaeff\Understudy\Understudy;

$repository = Understudy::for(BookRepository::class);
```

`for()` возвращает тип самого контракта, поэтому IDE и статический анализатор
видят в `$repository` обычный `BookRepository`. Несколько интерфейсов
объединяются:

```php
$double = Understudy::for(BookRepository::class, Countable::class);
```

Сейчас поддерживаются интерфейсы; классы-цели и `bypassFinals()` — следующий
этап работы.

### Стабы

```php
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;

use function Rasuvaeff\Understudy\when;

when(fn () => $repository->find(123))->returns($book);
when(fn () => $repository->find(404))->throws(new NotFound());
when(fn () => $repository->find(Arg::any()))->answers(
    fn (Invocation $call) => new Book(title: (string) $call->args[0]),
);

// По значению на вызов, дальше повторяется последнее.
when(fn () => $repository->mode())->returns('fast', 'slow');
```

Более поздний стаб на тот же вызов выигрывает, а ранние остаются запасными —
поэтому широкий стаб с `Arg::any()` может лежать под точечным.

### Проверки

```php
use function Rasuvaeff\Understudy\verify;

verify(fn () => $repository->save($book));                 // хотя бы раз
verify(fn () => $repository->save($book), times: 2);       // ровно дважды
verify(fn () => $repository->save($book), minimum: 2);     // без верхней границы
verify(fn () => $repository->ping(), never: true);

Understudy::unused($repository);                           // вообще ни разу
```

Каждый дубль пишет все вызовы, поэтому проверку не нужно готовить заранее.

### Чтение лога вызовов

```php
use Rasuvaeff\Understudy\Arg;

$calls = Understudy::calls(fn () => $repository->find(Arg::any()));

$calls[0]->args;          // [123]
$calls[0]->didReturn();   // true
$calls[0]->returned();    // чем ответил
$calls[1]->thrown();      // исключение, если бросил
```

`null` — полноценное возвращаемое значение, поэтому об исходе спрашивают
(`didReturn()`), а не выводят его из самого значения.

### Режимы

| Режим | Чем отвечает несовпавший вызов |
|---|---|
| Loose (по умолчанию) | типобезопасным значением: `null`, `0`, `''`, `[]`, пустой генератор … |
| Strict (`Understudy::strict($double)`) | немедленной ошибкой с именем метода |

Loose-дубль никогда не выдумывает значение, запуская чужой конструктор, и
никогда не отдаёт объект с пропущенным конструктором. Если безопасного
значения нет — он говорит об этом и подсказывает выход.

### Сообщения об ошибках

```text
Understudy `BookRepository` expected `tag('alpha', 2)` to be called exactly 1 time,
but it was never called.

The following calls to `tag` were made during this test:
    tag(*'beta'*, 2)
```

Звёздочками помечен разошедшийся аргумент — приём заимствован у
[NSubstitute](https://nsubstitute.github.io). `Understudy::label($double, '…')`
даёт дублю имя, когда их в тесте несколько на один контракт.

### Очистка

```php
Understudy::reset();
```

Адаптеры для Testo и PHPUnit будут вызывать это сами; пока их нет — вызывайте
в своём teardown.

## Безопасность

Understudy генерирует по классу на набор контрактов и вычисляет его один раз
за процесс. Она не загружает код из пользовательского ввода, не обращается к
файловой системе и держит всё состояние в `WeakMap` по ключу-объекту — а не по
`spl_object_id()`, который PHP переиспользует после сборки мусора.

Это dev-зависимость. В production её ставить не нужно.

## Примеры

Исполняемые скрипты — в [examples/](examples/).

## Разработка

```bash
make build          # validate, normalize, require-checker, cs, psalm, test
make cs-fix
make psalm
make test
make mutation       # infection, гейт 85% MSI
make release-check
```

Или напрямую через Docker:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

В `spikes/` лежат feasibility-фикстуры, на которых стоит дизайн; `bash
spikes/run.sh` прогоняет их на любом PHP 8.3+.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
