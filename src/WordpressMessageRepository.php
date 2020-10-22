<?php

namespace EventSauce\WordpressMessageRepository;

use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Header;
use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\MessageRepository;
use EventSauce\EventSourcing\Serialization\MessageSerializer;
use Generator;
use function json_decode;
use Ramsey\Uuid\Uuid;

class WordpressMessageRepository implements MessageRepository
{

    protected $connection;

    /**
     * @var MessageSerializer
     */
    protected $serializer;

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var int
     */
    private $jsonEncodeOptions;

    public function __construct($connection, MessageSerializer $serializer, string $tableName, int $jsonEncodeOptions = 0)
    {
        $this->connection = $connection->dbh;
        $this->serializer = $serializer;
        $this->tableName = $tableName;
        $this->jsonEncodeOptions = $jsonEncodeOptions;
    }

    public function persist(Message ...$messages)
    {
        if (count($messages) === 0) {
            return;
        }

        $sql = $this->baseSql($this->tableName);
        $params = [];
        $values = [];
        $types = "";

        foreach ($messages as $index => $message) {
            $payload = $this->serializer->serializeMessage($message);
            $values[] = "(?,?,?,?,?,?)";
            $types .= "sssiss";
            // order of values need to match with sql values order
            $params[] = $payload['headers'][Header::EVENT_ID] = $payload['headers'][Header::EVENT_ID] ?? Uuid::uuid4()->toString();
            $params[] = $payload['headers'][Header::EVENT_TYPE] ?? null;
            $params[] = $payload['headers'][Header::AGGREGATE_ROOT_ID] ?? null;
            $params[] = $payload['headers'][Header::AGGREGATE_ROOT_VERSION] ?? 0;
            $params[] = $this->removeTimeZone($payload['headers'][Header::TIME_OF_RECORDING]);
            $params[] = json_encode($payload, $this->jsonEncodeOptions);
        }

        $sql .= implode(', ', $values);

        if (!mysqli_begin_transaction($this->connection)) throw new \Error("start transaction failed!");
        $stm = mysqli_prepare($this->connection, $sql);
        if ($stm === false) throw new \Error("SQL broken! " . $sql);
        $res = mysqli_stmt_bind_param($stm, $types, ...$params);
        if ($res === false) throw new \Error("parameter wrong!");
        if (!mysqli_stmt_execute($stm)) throw new \Error("sql execution failed!");
        if (!mysqli_commit($this->connection)) throw new \Error("sql transaction commit failed!");
    }

    protected function baseSql(string $tableName): string
    {
        return "INSERT INTO {$tableName} (event_id, event_type, aggregate_root_id, aggregate_root_version, time_of_recording, payload) VALUES ";
    }

    public function retrieveAll(AggregateRootId $id): Generator
    {

        $aggregate_root_id = mysqli_real_escape_string($this->connection, $id->toString());
        $stm = mysqli_prepare($this->connection, "SELECT payload FROM {$this->tableName} WHERE aggregate_root_id = \"{$aggregate_root_id}\" ORDER BY aggregate_root_version ASC");
        mysqli_stmt_execute($stm);

        return $this->yieldMessagesForResult($stm);
    }

    public function retrieveEverything(): Generator
    {

        $stm = mysqli_prepare($this->connection, "SELECT payload FROM {$this->tableName} ORDER BY time_of_recording ASC");
        mysqli_stmt_execute($stm);

        mysqli_stmt_bind_result($stm, $payload);
        while (mysqli_stmt_fetch($stm)) {
            yield from $this->serializer->unserializePayload(json_decode($payload, true));
        }
        mysqli_stmt_close($stm);
    }

    public function retrieveAllAfterVersion(AggregateRootId $id, int $aggregateRootVersion): Generator
    {

        $aggregate_root_id_escaped = mysqli_real_escape_string($this->connection, $id->toString());
        $sql = "SELECT payload FROM {$this->tableName} WHERE aggregate_root_id = \"{$aggregate_root_id_escaped}\" AND aggregate_root_version > {$aggregateRootVersion} ORDER BY aggregate_root_version ASC";

        $stm = mysqli_prepare($this->connection, $sql);
        mysqli_stmt_execute($stm);

        return $this->yieldMessagesForResult($stm);
    }

    /**
     * @param Statement $stm
     * @return Generator|int
     */
    private function yieldMessagesForResult($stm)
    {
        mysqli_stmt_bind_result($stm, $payload);
        while (mysqli_stmt_fetch($stm)) {
            $messages = $this->serializer->unserializePayload(json_decode($payload, true));

            /* @var Message $message */
            foreach ($messages as $message) {
                yield $message;
            }
        }
        mysqli_stmt_close($stm);

        return (isset($message) ?
            ($message->header(Header::AGGREGATE_ROOT_VERSION) ?: 0)
            : 0);
    }

    private function removeTimeZone($dateTimeString)
    {
        return (empty($dateTimeString) ? $dateTimeString : explode("+", $dateTimeString)[0]);
    }
}
