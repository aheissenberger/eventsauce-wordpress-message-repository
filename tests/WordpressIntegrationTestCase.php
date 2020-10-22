<?php

namespace EventSauce\WordpressMessageRepository\Tests;

use EventSauce\WordpressMessageRepository\WordpressMessageRepository;
use EventSauce\EventSourcing\DefaultHeadersDecorator;
use EventSauce\EventSourcing\Header;
use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\Serialization\ConstructingMessageSerializer;
use EventSauce\EventSourcing\Serialization\MessageSerializer;
use EventSauce\EventSourcing\Time\Clock;
use EventSauce\EventSourcing\Time\TestClock;
use EventSauce\EventSourcing\UuidAggregateRootId;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use function iterator_to_array;

class WordpressIntegrationTestCase extends TestCase
{
    /**
     * @var WordpressMessageRepository
     */
    private $repository;

    /**
     * @var DefaultHeadersDecorator
     */
    private $decorator;

    /**
     * @var Clock
     */
    private $clock;

    protected function connection()
    {
        return (require __DIR__ . '/wpdb-connection.php');
    }

    protected function messageRepository(
        $connection,
        MessageSerializer $serializer,
        string $tableName
    ): WordpressMessageRepository {
        return new WordpressMessageRepository($connection, $serializer, $tableName);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->connection();
        $stmt = mysqli_prepare($connection->dbh, 'TRUNCATE TABLE domain_messages');
        mysqli_stmt_execute($stmt);
        $serializer = new ConstructingMessageSerializer();
        $this->clock = new TestClock();
        $this->decorator = new DefaultHeadersDecorator(null, $this->clock);
        $this->repository = $this->messageRepository($connection, $serializer, 'domain_messages');
    }

    /**
     * @test
     */
    public function it_works()
    {
        $aggregateRootId = UuidAggregateRootId::create();
        $this->repository->persist();
        $this->assertEmpty(iterator_to_array($this->repository->retrieveAll($aggregateRootId)));

        $eventId = Uuid::uuid4()->toString();
        $message = $this->decorator->decorate(new Message(new TestEvent(), [
            Header::EVENT_ID          => $eventId,
            Header::AGGREGATE_ROOT_ID => $aggregateRootId->toString(),
            Header::AGGREGATE_ROOT_VERSION => 10,
        ]));
        $this->repository->persist($message);
        $generator = $this->repository->retrieveAll($aggregateRootId);
        $retrievedMessage = iterator_to_array($generator, false)[0];
        $this->assertEquals($message, $retrievedMessage);
        $this->assertEquals(10, $generator->getReturn());
    }

    /**
     * @test
     */
    public function persisting_events_without_event_ids()
    {
        $message = $this->decorator->decorate(new Message(
            new TestEvent((new TestClock())->pointInTime()),
            [Header::AGGREGATE_ROOT_ID => Uuid::uuid4()->toString()]
        ));
        $this->repository->persist($message);
        $persistedMessages = iterator_to_array($this->repository->retrieveEverything());
        $this->assertCount(1, $persistedMessages);
        $this->assertNotEquals($message, $persistedMessages[0]);
    }

    /**
     * @test
     */
    public function retrieving_messages_after_a_specific_version()
    {
        $messages = [];

        $aggregateRootIdConcurrent = UuidAggregateRootId::create();
        $messages[] = $this->decorator->decorate(new Message(new TestEvent(), [
            Header::EVENT_ID          => Uuid::uuid4()->toString(),
            Header::AGGREGATE_ROOT_ID => $aggregateRootIdConcurrent->toString(),
            Header::AGGREGATE_ROOT_VERSION => 15,
        ]));

        $aggregateRootId = UuidAggregateRootId::create();
        $messages[] = $this->decorator->decorate(new Message(new TestEvent(), [
            Header::EVENT_ID          => Uuid::uuid4()->toString(),
            Header::AGGREGATE_ROOT_ID => $aggregateRootId->toString(),
            Header::AGGREGATE_ROOT_VERSION => 10,
        ]));
        $messages[] = $this->decorator->decorate(new Message(new TestEvent(), [
            Header::EVENT_ID          => $lastEventId = Uuid::uuid4()->toString(),
            Header::AGGREGATE_ROOT_ID => $aggregateRootId->toString(),
            Header::AGGREGATE_ROOT_VERSION => 11,
        ]));

        $this->repository->persist(...$messages);

        $generator = $this->repository->retrieveAllAfterVersion($aggregateRootId, 10);
        /** @var Message[] $messages */
        $messages = iterator_to_array($generator);
        $this->assertEquals(11, $generator->getReturn());
        $this->assertCount(1, $messages);
        $this->assertEquals($lastEventId, $messages[0]->header(Header::EVENT_ID));
    }
}
