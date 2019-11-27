<?php
/**
 * S3
 *
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2019/11/26
 * @time 18:05
 */

namespace Luolongfei\App\Aws;

use Aws\S3\S3Client;
use Aws\Credentials\CredentialProvider;
use Aws\Exception\AwsException;

class S3
{
    /**
     * @var S3Client
     */
    protected static $S3Client;

    /**
     * @var string 桶名
     */
    public static $bucket;

    /**
     * @param string $bucket
     *
     * @return S3Client
     */
    public static function getS3Client($bucket = '')
    {
        if (!self::$S3Client instanceof S3Client) {
            self::$S3Client = new S3Client(self::getConfig());
            self::$bucket = $bucket ?: env('AWS_S3_BUCKET');
        }

        return self::$S3Client;
    }

    public static function putObject($file, $key = '', $fileType = 'image/png', $ACL = 'public-read')
    {
        $client = self::getS3Client();
        return $client->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key . basename($file),
            'SourceFile' => $file,
            'ContentType' => $fileType,
            'ACL' => $ACL,
            'debug' => false
        ]);
    }

    protected static function getConfig()
    {
        return [
            'version' => 'latest',
//            'endpoint' => 'https://s3.ap-northeast-1.amazonaws.com',
            'region' => env('AWS_S3_REGION'),
            'credentials' => [
                'key' => env('AWS_S3_ACCESS_KEY'),
                'secret' => env('AWS_S3_ACCESS_SECRET'),
            ]
        ];
    }
}