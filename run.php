<?php
/**
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2019/11/19
 * @time 17:15
 */

error_reporting(E_ERROR);
ini_set('display_errors', 1);
set_time_limit(0);

define('DS', DIRECTORY_SEPARATOR);
define('ROOT_PATH', realpath(__DIR__));

date_default_timezone_set('Asia/Shanghai');

/**
 * 定制错误处理
 */
register_shutdown_function('customize_error_handler');
function customize_error_handler()
{
    if (!is_null($error = error_get_last())) {
        system_log($error);
    }
}

/**
 * 写日志
 *
 * @param $content
 * @param array $response
 * @param string $fileName
 */
function system_log($content, array $response = [], $fileName = '')
{
    try {
        $path = sprintf('%s/logs/%s/', ROOT_PATH, date('Y-m'));
        $file = $path . ($fileName ?: date('d')) . '.log';

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
            chmod($path, 0777);
        }

        $handle = fopen($file, 'a'); // 追加而非覆盖

        if (!filesize($file)) {
            chmod($file, 0666);
        }

        $msg = sprintf(
            "[%s] %s %s\n",
            date('Y-m-d H:i:s'),
            is_string($content) ? $content : json_encode($content),
            $response ? json_encode($response, JSON_UNESCAPED_UNICODE) : '');
        fwrite($handle, $msg);
        echo $msg;

        fclose($handle);
    } catch (\Exception $e) {
        // DO NOTHING
    }
}

require __DIR__ . '/vendor/autoload.php';

use Curl\Curl;
use Curl\MultiCurl;

class HeTang
{
    /**
     * @var HeTang
     */
    protected static $instance;

    /**
     * @var Curl
     */
    protected static $client;

    /**
     * @var MultiCurl
     */
    protected static $multiClient;

    /**
     * @throws ErrorException
     * @throws \Exception
     */
    public function handle()
    {
        $startTime = time();
        $ht = self::getInstance();

        $curl = $ht->getClient();
        $multiClient = $this->getMultiClient();
        $multiClient->setTimeout(233);

        // 获取所有数值记录名
        $curl->get('https://physionet.org/physiobank/database/mimic3wdb/matched/RECORDS-numerics');
        if (!preg_match_all('/(?P<numerics>p\d+\/p\d+\/p.*?)(?:\n|$)/i', $curl->response, $matches)) {
            throw new \Exception('匹配numerics失败');
        }
        $numerics = $matches['numerics'];

        $errorPages = [];
        $existBP = [];
        $multiClient->success(function ($instance) use (&$existBP) {
            $rawResponse = $instance->rawResponse;
            $url = $instance->url;
            $numericName = preg_match('/\/matched\/(?P<numeric_name>.*?)n\.hea/i', $url, $m) ? $m['numeric_name'] : '';
            if (stripos($rawResponse, 'ABP') !== false) {
                $existBP[] = $numericName; // 保存去掉n后的名称
                system_log(sprintf('发现含有血压数据的画面：%s', $url));
            } else {
                system_log(sprintf('发现不含血压数据的画面：%s', $url));
            }

            return true;
        });
        $multiClient->error(function ($instance) use (&$errorPages) {
            system_log(sprintf('multiClient查询是否存在血压数据 curl请求页面出错：%s %s#%s', $instance->url, $instance->errorCode, $instance->errorMessage));
            $errorPages[] = [
                'url' => $instance->url,
                'error_reason' => sprintf('%s#%s', $instance->errorCode, $instance->errorMessage)
            ];

            return false;
        });

        $size = 500; // 同一批次最多同时发起的请求个数
        $numericChunks = array_chunk($numerics, $size);
        foreach ($numericChunks as $numericChunk) {
            foreach ($numericChunk as $numeric) {
                $multiClient->addGet(
                    sprintf(
                        'https://physionet.org/physiobank/database/mimic3wdb/matched/%s.hea',
                        $numeric
                    )
                );
            }
            system_log(sprintf('等待中，直到前%d个请求完成，防止请求过于频繁', $size));
            $multiClient->start(); // Blocks until all items in the queue have been processed.
            system_log(sprintf('前%d个请求已完成', $size));
        }

        system_log(sprintf(
                '完成筛选numerics，共%d位病人，有%d位病人存在血压数据，共耗时%s分钟',
                count($numerics),
                count($existBP),
                floor((time() - $startTime) / 60)
            )
        );

        // 获取所有波形记录名称
        $curl->get('https://physionet.org/physiobank/database/mimic3wdb/matched/RECORDS-waveforms');
        if (!preg_match_all('/(?P<waveforms>p\d+\/p\d+\/.*?)(?:\n|$)/i', $curl->response, $matches)) {
            throw new \Exception('匹配waveforms失败');
        }
        $waveforms = $matches['waveforms'];

        // 既有血压数据又有PPG数据的病人
        $result = [];

        // 需要一个新CURL对象在回调中执行第二步操作
        $multiClient2 = new MultiCurl();
        $multiClient2->setTimeout(233);
        $multiClient2->success(function ($instance) use (&$result) {
            $rawResponse = $instance->rawResponse;
            $url = explode('?', $instance->url)[0];
            $rootPath = preg_match(
                '/(?P<rootPath>https?:\/\/physionet\.org\/physiobank\/database\/mimic3wdb\/matched\/p\d+\/p\d+\/)/i',
                $url,
                $r
            ) ? $r['rootPath'] : '';
            $numericName = preg_match('/\?numericName=p\d+\/p\d+\/(?P<numericName>.*?)$/i', $instance->url, $n) ? $n['numericName'] : ''; // 含n，仅取名
            $numericUrl = sprintf('%s%s.hea', $rootPath, $numericName);
            if (stripos($rawResponse, 'PLETH') !== false) { // 存在ppg波形
                $data = [
                    'rootPath' => $rootPath,
                    'layoutUrl' => $url,
                    'numericUrl' => $numericUrl
                ];
                $result[] = $data;
                system_log(sprintf('发现PPG数据：%s', $url));
                system_log(sprintf("发现既有BP又有PPG数据的病人：\n%s\n%s\n", $url, $numericUrl), [], 'result');
            } else {
                system_log(sprintf('未发现PPG数据：%s', $url));
            }
        });
        $multiClient2->error(function ($instance) use (&$errorPages) {
            system_log(sprintf('multiClient2获取layout画面 curl请求页面出错：%s %s#%s', $instance->url, $instance->errorCode, $instance->errorMessage));
            $errorPages[] = [
                'url' => $instance->url,
                'error_reason' => sprintf('%s#%s', $instance->errorCode, $instance->errorMessage)
            ];

            return false;
        });

        // 重新定义之前multiClient回调
        $multiClient->success(function ($instance) use (&$multiClient2) {
            $rawResponse = $instance->rawResponse;
            $url = $instance->url;
            $path = preg_match('/\/mimic3wdb\/matched\/(?P<path>p\d+\/p\d+\/)/i', $url, $p) ? $p['path'] : '';
            $layoutName = preg_match('/(?P<layout>\d+_layout)\s\d+/i', $rawResponse, $m) ? $m['layout'] : '';
            $layoutUrl = sprintf('%s%s%s.hea', 'https://physionet.org/physiobank/database/mimic3wdb/matched/', $path, $layoutName);
            $numericName = preg_match('/\/matched\/(?P<numeric_name>.*?)\.hea/i', $url, $m) ? $m['numeric_name'] : '';
            if ($layoutName && $path) {
                $multiClient2->addGet($layoutUrl . '?numericName=' . $numericName . 'n');
            } else {
                system_log(sprintf('此地址下未发现layout名：%s', $url));
            }

            return true;
        });
        $multiClient->error(function ($instance) use (&$errorPages) {
            system_log(sprintf('multiClient获取 layout url， curl请求页面出错：%s %s#%s', $instance->url, $instance->errorCode, $instance->errorMessage));
            $errorPages[] = [
                'url' => $instance->url,
                'error_reason' => sprintf('%s#%s', $instance->errorCode, $instance->errorMessage)
            ];

            return false;
        });

        $startWaveformTime = time();
        $waveformChunks = array_chunk($waveforms, $size);
        foreach ($waveformChunks as $waveformChunk) {
            foreach ($waveformChunk as $waveform) {
                if (!in_array($waveform, $existBP)) {
                    continue;
                }

                $multiClient->addGet(
                    sprintf(
                        'https://physionet.org/physiobank/database/mimic3wdb/matched/%s.hea',
                        $waveform
                    )
                );
            }
            system_log(sprintf('等待中，直到前%d个请求完成，防止请求过于频繁', $size));
            $multiClient->start(); // Blocks until all items in the queue have been processed.
            $multiClient2->start();
            system_log(sprintf('前%d个请求已完成', $size));
        }

        system_log(sprintf('完成PPG数据的筛选，共耗时%s分钟', floor((time() - $startWaveformTime) / 60)));
        system_log(sprintf('同时存在血压与PPG数据的病人共%d位', count($result)));
        system_log(sprintf('所有操作共耗时%s分钟', floor((time() - $startTime) / 60)));

        // 拜拜了您勒
        self::$client && self::$client->close();
        self::$multiClient && self::$multiClient->close();
        $multiClient2->close();
    }

    /**
     * @return HeTang
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof HeTang) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return Curl
     * @throws ErrorException
     */
    public function getClient()
    {
        if (!self::$client instanceof Curl) {
            self::$client = new Curl();
        }

        return self::$client;
    }

    /**
     * @return MultiCurl
     */
    public function getMultiClient()
    {
        if (!self::$multiClient instanceof MultiCurl) {
            self::$multiClient = new MultiCurl();
        }

        return self::$multiClient;
    }
}

try {
    HeTang::getInstance()->handle();
} catch (\Exception $e) {
    system_log($e->getMessage());
}
