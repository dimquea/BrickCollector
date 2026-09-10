<?php

namespace App\Catalog\Import;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOStatement;

/**
 * Buffered bulk insert for the import.
 *
 * The query builder is the wrong tool for a million and a half rows: it builds
 * and prepares a fresh statement on every call, and a chunk of 2,000 rows is a
 * statement with 20,000 placeholders for SQLite to parse each time. Measured on
 * the real archive, that difference is minutes.
 *
 * Here one statement of a fixed width is prepared once and executed repeatedly.
 * A remainder that does not fill the batch is flushed through a second,
 * single-row statement.
 */
class BulkInsert
{
    private PDO $pdo;

    private PDOStatement $batch;

    private PDOStatement $single;

    private array $buffer = [];

    private int $rows = 0;

    private int $bufferedRows = 0;

    /**
     * @param  string[]  $columns
     * @param  int  $batchSize  rows per execute; 100 measured fastest, and keeps
     *                          the placeholder count far below SQLite's limit
     */
    public function __construct(
        private readonly string $table,
        private readonly array $columns,
        private readonly int $batchSize = 100,
    ) {
        $this->pdo = DB::getPdo();

        $columnList = '('.implode(',', $this->columns).')';
        $placeholders = '('.implode(',', array_fill(0, count($this->columns), '?')).')';

        $this->batch = $this->pdo->prepare(
            "INSERT INTO {$this->table} {$columnList} VALUES "
            .implode(',', array_fill(0, $this->batchSize, $placeholders))
        );

        $this->single = $this->pdo->prepare(
            "INSERT INTO {$this->table} {$columnList} VALUES {$placeholders}"
        );
    }

    /** @param array<int, mixed> $values in the order given to the constructor */
    public function add(array $values): void
    {
        foreach ($values as $value) {
            $this->buffer[] = $value;
        }

        $this->rows++;

        if (++$this->bufferedRows >= $this->batchSize) {
            $this->batch->execute($this->buffer);
            $this->buffer = [];
            $this->bufferedRows = 0;
        }
    }

    /** Writes whatever is left in the buffer. Always call this. */
    public function flush(): void
    {
        $width = count($this->columns);

        while ($this->buffer) {
            $this->single->execute(array_splice($this->buffer, 0, $width));
        }

        $this->bufferedRows = 0;
    }

    public function rows(): int
    {
        return $this->rows;
    }
}
