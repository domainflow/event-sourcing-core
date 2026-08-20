<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Acceptance;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Storage\InMemoryEventStorage;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use DomainFlow\Uuid\UuidV6;
use Exception;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionException;

#[CoversNothing]
final class ProductAggregateAcceptanceTest extends TestCase
{
    private EventStorageInterface $store;
    private EventSourcingFacade $facade;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStorage();
        $this->facade = new EventSourcingFacade($this->store);
    }

    /**
     * @throws ReflectionException| DateMalformedStringException|Exception
     */
    public function test_product_lifecycle_flow(): void
    {
        $productId = ProductId::new();

        $this->facade->apply(
            ProductAggregate::class,
            $productId,
            function (ProductAggregate $product) use ($productId) {
                $product->init($productId);
                $product->updateName("Awesome Widgets");
                $product->increaseQuantity(10);
                $product->decreaseQuantity(2);
                $product->changePrice(19.99);
                $product->changePrice(24.99);
                $product->decreaseQuantity(2);
                $product->archive();
            }
        );

        /** @var ProductAggregate $loaded */
        $loaded = $this->facade->load(ProductAggregate::class, $productId);

        $this->assertSame('Awesome Widgets', $loaded->getName());
        $this->assertSame(24.99, $loaded->getPrice());
        $this->assertSame(6, $loaded->getQuantity());
        $this->assertTrue($loaded->isArchived());

        $manual = new ProductAggregate();
        $manual->init($productId);

        foreach ($this->store->retrieveEvents($productId) as $event) {
            $manual->applyEvent($event);
        }

        $this->assertSame('Awesome Widgets', $manual->getName());
        $this->assertSame(24.99, $manual->getPrice());
        $this->assertSame(6, $manual->getQuantity());
        $this->assertTrue($manual->isArchived());
    }
}

// Dummy classes
final readonly class ProductId implements EntityIdentifierInterface
{
    public function __construct(
        private string $value
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }
    public function equals(
        EntityIdentifierInterface $other
    ): bool {
        return (string) $other === $this->value;
    }

    /**
     * @throws RandomException
     */
    public static function new(): self
    {
        return new self(
            UuidV6::generate()->jsonSerialize()
        );
    }
    public static function fromString(
        string $value
    ): self {
        return new self($value);
    }
}

final class QuantityIncreased implements DomainEventInterface
{
    use HasEventMetadata;

    public function __construct(
        private readonly ProductId $productId,
        public int $amount,
        protected EventVersion $version
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->productId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function toArray(): array
    {
        return ['productId' => (string) $this->productId, 'amount' => $this->amount, 'version' => $this->version];
    }

    /**
     * @param array<string, mixed<string|int>> $data
     * @throws Exception
     */
    public static function fromArray(
        array $data
    ): self {
        return new self(
            ProductId::fromString($data['productId']),
            (int) $data['amount'],
            EventVersion::fromInt((int) ($data['version'] ?? 1))
        );
    }
    public static function getFactory(): callable
    {
        return [self::class, 'fromArray'];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class QuantityDecreased implements DomainEventInterface
{
    use HasEventMetadata;

    public function __construct(
        private readonly ProductId $productId,
        public int $amount,
        protected EventVersion $version
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->productId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function toArray(): array
    {
        return ['productId' => (string) $this->productId, 'amount' => $this->amount, 'version' => $this->version];
    }

    public static function fromArray(
        array $data
    ): self {
        return new self(
            ProductId::fromString($data['productId']),
            (int) $data['amount'],
            EventVersion::fromInt((int) ($data['version'] ?? 1))
        );
    }
    public static function getFactory(): callable
    {
        return [self::class, 'fromArray'];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class PriceChanged implements DomainEventInterface
{
    use HasEventMetadata;

    public function __construct(
        private readonly ProductId $productId,
        public float $newPrice,
        protected EventVersion $version
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->productId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
    public function getVersion(): EventVersion
    {
        return $this->version;
    }
    public function toArray(): array
    {
        return ['productId' => (string) $this->productId, 'newPrice' => $this->newPrice, 'version' => $this->version];
    }

    /**
     * @param array<string, mixed<string|int>> $data
     */
    public static function fromArray(
        array $data
    ): self {
        return new self(
            ProductId::fromString($data['productId']),
            (float) $data['newPrice'],
            EventVersion::fromInt((int) ($data['version'] ?? 1))
        );
    }
    public static function getFactory(): callable
    {
        return [self::class, 'fromArray'];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class NameUpdated implements DomainEventInterface
{
    use HasEventMetadata;

    public function __construct(
        private readonly ProductId $productId,
        public string $newName,
        protected EventVersion $version
    ) {
    }
    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->productId;
    }
    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
    public function getVersion(): EventVersion
    {
        return $this->version;
    }
    public function toArray(): array
    {
        return ['productId' => (string) $this->productId, 'newName' => $this->newName, 'version' => $this->version];
    }
    public static function fromArray(
        array $data
    ): self {
        return new self(
            ProductId::fromString($data['productId']),
            (string) $data['newName'],
            EventVersion::fromInt((int) ($data['version'] ?? 1))
        );
    }
    public static function getFactory(): callable
    {
        return [self::class, 'fromArray'];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }

}

final class ProductArchived implements DomainEventInterface
{
    use HasEventMetadata;

    public function __construct(
        private readonly ProductId $productId,
        private EventVersion $version
    ) {
    }
    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->productId;
    }
    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
    public function getVersion(): EventVersion
    {
        return $this->version;
    }
    public function toArray(): array
    {
        return ['productId' => (string) $this->productId, 'version' => $this->version];
    }
    public static function fromArray(
        array $data
    ): self {
        return new self(
            ProductId::fromString($data['productId']),
            EventVersion::fromInt((int) ($data['version'] ?? 1))
        );
    }
    public static function getFactory(): callable
    {
        return [self::class, 'fromArray'];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class ProductAggregate extends AggregateRoot
{
    private ProductId $id;
    private int $quantity = 0;
    private float $price = 0.0;
    private string $name = '';
    private bool $archived = false;

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function init(
        ProductId $id
    ): void {
        $this->id = $id;
    }

    public function increaseQuantity(
        int $amount
    ): void {
        $this->applyEvent(new QuantityIncreased($this->id, $amount, EventVersion::unassigned()));
    }

    public function decreaseQuantity(
        int $amount
    ): void {
        $this->applyEvent(new QuantityDecreased($this->id, $amount, EventVersion::unassigned()));
    }

    public function changePrice(
        float $newPrice
    ): void {
        $this->applyEvent(new PriceChanged($this->id, $newPrice, EventVersion::unassigned()));
    }

    public function updateName(
        string $newName
    ): void {
        $this->applyEvent(new NameUpdated($this->id, $newName, EventVersion::unassigned()));
    }

    public function archive(): void
    {
        $this->applyEvent(new ProductArchived($this->id, EventVersion::unassigned()));
    }

    protected function applyQuantityIncreased(
        QuantityIncreased $e
    ): void {
        $this->id = $e->getAggregateId();
        $this->quantity += $e->amount;
    }

    protected function applyQuantityDecreased(
        QuantityDecreased $e
    ): void {
        $this->id = $e->getAggregateId();
        $this->quantity -= $e->amount;
    }

    protected function applyPriceChanged(
        PriceChanged $e
    ): void {
        $this->id = $e->getAggregateId();
        $this->price = $e->newPrice;
    }

    protected function applyNameUpdated(
        NameUpdated $e
    ): void {
        $this->id = $e->getAggregateId();
        $this->name = $e->newName;
    }

    protected function applyProductArchived(
        ProductArchived $e
    ): void {
        $this->id = $e->getAggregateId();
        $this->archived = true;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getPrice(): float
    {
        return $this->price;
    }
    public function isArchived(): bool
    {
        return $this->archived;
    }
}
