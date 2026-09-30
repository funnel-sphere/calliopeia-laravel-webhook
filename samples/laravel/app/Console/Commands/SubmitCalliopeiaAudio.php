<?php

namespace App\Console\Commands;

use App\Models\CalliopeiaSubmission;
use FunnelSphere\CalliopeiaWebhook\Client\CalliopeiaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SubmitCalliopeiaAudio extends Command
{
    protected $signature = 'calliopeia:submit {requestId} {audio?}
        {--seconds= : Duration in seconds (required for a new request)}
        {--webhook-endpoint= : Optional registered Calliopeia endpoint ID}';
    protected $description = 'Upload audio once; safely retry invocation using the persisted ticket';

    public function handle(CalliopeiaClient $api): int
    {
        $id = (string) $this->argument('requestId');
        if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $id)) {
            $this->error('requestId: use 1–128 letters, numbers, underscores or hyphens.');
            return self::FAILURE;
        }
        // 15 minutes exceeds the SDK upload timeout (600s) + API request timeout.
        // All workers must use the same lock-capable cache store.
        $lock = Cache::lock('calliopeia-sample:'.$id, 900);
        if (!$lock->get()) {
            $this->error('This request is already running. Retry after it finishes.');
            return self::FAILURE;
        }
        try {
            $row = CalliopeiaSubmission::find($id);
            if ($row && ($this->argument('audio') !== null || $this->option('seconds') !== null
                || $this->option('webhook-endpoint') !== null)) {
                $this->error('This request already exists. Retry with requestId only; use a new ID for new audio.');
                return self::FAILURE;
            }
            if (!$row) {
                $path = realpath((string) $this->argument('audio'));
                $seconds = filter_var($this->option('seconds'), FILTER_VALIDATE_FLOAT);
                if (!$path || !is_file($path) || !is_readable($path)
                    || $seconds === false || !is_finite($seconds) || $seconds <= 0
                    || filesize($path) < 1 || filesize($path) > 2147483647) {
                    $this->error('Provide a readable audio file (1–2147483647 bytes) and positive --seconds.');
                    return self::FAILURE;
                }
                $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                    'm4a', 'mp4' => 'audio/mp4', 'wav' => 'audio/wav', 'mp3' => 'audio/mpeg',
                    default => null,
                };
                if (!$mime) { $this->error('Use M4A, WAV or MP3.'); return self::FAILURE; }
                $endpoint = $this->option('webhook-endpoint');
                $row = CalliopeiaSubmission::create(['id' => $id, 'request' => [
                    'path' => $path, 'name' => basename($path), 'bytes' => filesize($path),
                    'sha256' => hash_file('sha256', $path), 'seconds' => $seconds, 'mime' => $mime,
                    'options' => $endpoint ? ['responseMode' => 'WEBHOOK', 'webhookEndpointId' => $endpoint] : [],
                ]]);
            }
            if ($row->job_id) { $this->line($row->job_id); return self::SUCCESS; }
            $request = $row->request;
            if (!$row->ticket) {
                if (!is_readable($request['path']) || hash_file('sha256', $request['path']) !== $request['sha256']) {
                    $this->error('Original audio is missing or changed. Restore it before retrying.');
                    return self::FAILURE;
                }
                $ticket = $api->createAudioUpload($request['name'], $request['mime']);
                $api->uploadAudio($request['path'], $ticket);
                // Persist BEFORE invocation. Do not retain the signed upload URL.
                $row->ticket = array_intersect_key($ticket, array_flip(['objectKey', 'contentType']));
                $row->save();
            }
            $accepted = $api->invokeAudioJob($row->ticket, $request['name'], $request['bytes'],
                $id, $request['seconds'], $request['options']);
            $row->job_id = $accepted['job']['id'];
            $row->save();
            $this->line($row->job_id);
            return self::SUCCESS;
        } catch (Throwable $error) {
            // Network exception text can contain signed URLs. Never print it here.
            $this->error('Submission failed ('.class_basename($error).'). Retry with the same requestId only.');
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
