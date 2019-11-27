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
     * @param string $bucket
     *
     * @return S3Client
     */
    public static function getS3Client()
    {
        if (!self::$S3Client instanceof S3Client) {
            self::$S3Client = new S3Client(self::getConfig());
        }

        return self::$S3Client;
    }

    /**
     * 上传文件
     *
     * @param $file
     * @param string $path 在桶下面的路径，通过 桶名 + key 即能访问到文件
     * @param string $bucket 桶名
     * @param string $ACL
     *
     * @return \Aws\Result
     */
    public static function putObject($file, $path = '', $bucket = '', $ACL = 'public-read')
    {
        $client = self::getS3Client();
        return $client->putObject([
            'Bucket' => $bucket ?: env('AWS_S3_BUCKET'),
            'Key' => $path . basename($file),
            'SourceFile' => $file,
//            'ContentType' => $contentType,
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