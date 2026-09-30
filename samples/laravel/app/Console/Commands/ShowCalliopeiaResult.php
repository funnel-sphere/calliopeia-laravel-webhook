<?php

namespace App\Console\Commands;

use App\Models\CalliopeiaSubmission;
use FunnelSphere\CalliopeiaWebhook\Client\CalliopeiaClient;
use Illuminate\Console\Command;
use Throwable;

final class ShowCalliopeiaResult extends Command
{
    protected $signature = 'calliopeia:result {requestId} {--show : Print the result; it may contain personal data}';
    protected $description = 'Fetch the current job status and persist the encrypted result';

    public function handle(CalliopeiaClient $api): int
    {
        $row = CalliopeiaSubmission::find($this->argument('requestId'));
        if (!$row?->job_id) { $this->error('No accepted job for this request.'); return self::FAILURE; }
        try {
            $result = $api->getJob($row->job_id);
            $row->result = $result;
            $row->save();
            $status = $result['job']['status'];
            $this->line($status);
            if ($this->option('show')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
            return in_array($status, ['FAILED', 'ERROR', 'REVIEW_REQUIRED'], true) ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Result fetch failed ('.class_basename($error).'). Retry later.');
            return self::FAILURE;
        }
    }
}
