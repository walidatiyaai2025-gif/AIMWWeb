<?php

namespace App\AI\Durable;

use Illuminate\Queue\DatabaseQueue;
use RuntimeException;

final class BoundedDatabaseQueue extends DatabaseQueue
{
    protected function pushToDatabase($queue, $payload, $delay = 0, $attempts = 0)
    {
        return $this->database->transaction(function () use ($queue, $payload, $delay, $attempts) {
            $this->database->table('durable_ai_gate')->where('id', 1)->lockForUpdate()->first();
            if ($this->database->table($this->table)->count() >= (int) config('cpanel.max_queued_jobs')) {
                throw new RuntimeException('cPanel queue admission capacity reached; no job was added.');
            }

            return parent::pushToDatabase($queue, $payload, $delay, $attempts);
        });
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        foreach ((array) $jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }
}
