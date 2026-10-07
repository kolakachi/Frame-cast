<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\Storage;

/** B2 privacy is a bucket property. An object visibility option alone is not proof. */
class PrivateBucketGuard
{
    private array $checked = [];

    public function assertPrivate(string $disk): void
    {
        if ($disk !== 'create_private') throw new \RuntimeException('Create requires its dedicated private storage disk.');
        $config = config('filesystems.disks.'.$disk, []);
        $host = parse_url((string) ($config['endpoint'] ?? ''), PHP_URL_HOST);
        if (($config['driver'] ?? '') !== 's3' || empty($config['bucket']) || empty($config['endpoint'])
            || ! str_starts_with($config['endpoint'], 'https://') || ($config['visibility'] ?? '') !== 'private'
            // This ACL check proves B2 bucket privacy, not arbitrary S3 bucket-policy privacy.
            || ! preg_match('/^s3\.[a-z0-9-]+\.backblazeb2\.com$/D', (string) $host)) {
            throw new \RuntimeException('Private Create storage is not configured.');
        }
        $fingerprint = hash('sha256', json_encode($config));
        if (($this->checked[$fingerprint] ?? 0) > time()) return;
        $acl = Storage::disk($disk)->getClient()->getBucketAcl(['Bucket' => $config['bucket']]);
        $owner = $acl['Owner']['ID'] ?? null;
        $grants = $acl['Grants'] ?? [];
        if (! $owner || ! $grants) throw new \RuntimeException('Could not verify private Create bucket access.');
        foreach ($grants as $grant) {
            $grantee = $grant['Grantee'] ?? [];
            if (($grantee['Type'] ?? '') !== 'CanonicalUser' || ($grantee['ID'] ?? null) !== $owner) {
                throw new \RuntimeException('Create bucket allows access beyond its owner. Use a private bucket.');
            }
        }
        $this->checked[$fingerprint] = time() + 300;
    }
}
