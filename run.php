<?php
/**
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2019/11/19
 * @time 17:15
 */

error_reporting(E_ERROR);
ini_set('display_errors', 1);
ini_set('memory_limit', '666M');
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

require __DIR__ . '/vendor/autoload.php';

use Curl\Curl;
use Curl\MultiCurl;
use Luolongfei\App\Aws\S3;

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
     * @var string 血压类型 ABP or NBP
     */
    public $BPType = 'ABP';

    /**
     * @var int 最大请求并发数
     */
    public $concurrentNum = 500;

    /**
     * @var int 最大并发下载多少个病人的数据
     */
    public $concurrentDownloadNum = 4;

    /**
     * @var array 所有命令行传参
     */
    public $allParams = [];

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

        $existBP = [];
        $multiClient->success(function ($instance) use (&$existBP) {
            $rawResponse = $instance->rawResponse;
            $url = $instance->url;
            $numericName = preg_match('/\/matched\/(?P<numeric_name>.*?)n\.hea/i', $url, $m) ? $m['numeric_name'] : '';
            if (stripos($rawResponse, $this->BPType) !== false) {
                $existBP[] = $numericName; // 保存去掉n后的名称
                system_log(sprintf('发现含有血压数据的画面：%s', $url));
            } else {
                system_log(sprintf('发现不含血压数据的画面：%s', $url));
            }

            return true;
        });
        $multiClient->error(function ($instance) {
            system_log(sprintf('multiClient查询是否存在血压数据 curl请求页面出错：%s %s#%s', $instance->url, $instance->errorCode, $instance->errorMessage));

            return false;
        });

        $size = $this->concurrentNum; // 同一批次最多同时发起的请求个数
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
                '完成筛选numerics，共%d位病人，有%d位病人存在血压数据，共耗时%s',
                count($numerics),
                count($existBP),
                self::formatTimeInterval($startTime, time())
            )
        );

        sleep(1);

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
            $peopleUrl = preg_match(
                '/(?P<rootPath>https?:\/\/physionet\.org\/physiobank\/database\/mimic3wdb\/matched\/p\d+\/p\d+\/)/i',
                $url,
                $r
            ) ? $r['rootPath'] : '';
            $numericName = self::getQuery('numericName', $instance->url); // 含n，仅取名
            $numericUrl = sprintf('%s%s.hea', $peopleUrl, $numericName);
            $layoutNum = preg_match('/\/(?P<layoutNum>\d+)_layout\.hea/i', $url, $m) ? $m['layoutNum'] : '';
            if (stripos($rawResponse, 'PLETH') !== false) { // 存在ppg波形
                $result[] = [
                    'peopleUrl' => $peopleUrl,
                    'layoutNum' => $layoutNum
                ];
                system_log(sprintf('发现PPG数据：%s', $url));
                system_log(sprintf("发现既有BP又有PPG数据的病人：\n%s\n%s\n", $url, $numericUrl), [], 'result');
            } else {
                system_log(sprintf('未发现PPG数据：%s', $url));
            }
        });
        $multiClient2->error(function ($instance) {
            system_log(sprintf('multiClient2获取layout画面 curl请求页面出错：%s %s#%s', $instance->url, $instance->errorCode, $instance->errorMessage));

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
        $multiClient->error(function ($instance) {
            system_log(sprintf('multiClient获取 layout url， curl请求页面出错：%s %s#%s', $instance->url, $instance->errorCode, $instance->errorMessage));

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

        system_log(sprintf('完成PPG数据的筛选，共耗时%s', self::formatTimeInterval($startWaveformTime, time())));
        system_log(sprintf('同时存在血压与PPG数据的病人共%d位', count($result)));
        system_log(sprintf('所有筛选操作共耗时%s', self::formatTimeInterval($startTime, time())));

        sleep(1);

        /**
         * 由multiClient2发起下载数据动作
         */
        $multiClient2->setOpt(CURLOPT_ENCODING, ''); // 发送所有支持的编码类型
        $multiClient2->setTimeout(0); // 下载的文件过大时会消耗过多的时间
        $multiClient2->success(function ($instance) {
            system_log(sprintf('成功下载文件：%s', $instance->url));

            return true;
        });
        $multiClient2->error(function ($instance) {
            system_log(sprintf('multiClient2下载dat文件失败，curl请求出错：%s %s#%s', $instance->url, $instance->errorCode, $instance->errorMessage));

            return false;
        });

        /**
         * 由multiClient访问病人画面，以取得需要下载的dat文件地址
         */
        $compTasks = []; // 待压缩任务
        $multiClient->success(function ($instance) use (&$multiClient2, &$compTasks) {
            $rawResponse = $instance->rawResponse;
            $peopleUrl = explode('?', $instance->url)[0];
            $layoutNum = self::getQuery('layoutNum', $instance->url);

            // 匹配所有dat文件名
            if (preg_match_all(sprintf('/href="(?P<datFile>(?:%s_\d+|%sn)\.dat)"/i', $layoutNum, $layoutNum), $rawResponse, $d)) {
                // 每个病人一个文件夹
                $people = preg_match('/\/database\/mimic3wdb\/matched\/(?P<path>p\d+\/p\d+\/)/i', $peopleUrl, $p) ? $p['path'] : '';
                $path = sprintf('%s/data/%s', ROOT_PATH, $people);
                if (!is_dir($path)) {
                    mkdir($path, 0777, true);
                    chmod($path, 0777);
                }

                // 添加压缩任务
                $compTasks[] = sprintf('%s@%s', $path, $layoutNum);

                $datFiles = $d['datFile'];
                foreach ($datFiles as $datFile) {
                    $datFileName = sprintf('%s%s', $path, $datFile);
                    if (file_exists($datFileName) && filesize($datFileName)) { // 防止重复下载
                        system_log(sprintf('检测到已存在文件，将不重复下载：%s', $datFileName));
                        continue;
                    }
                    $multiClient2->addDownload(sprintf('%s%s', $peopleUrl, $datFile), $datFileName);
                }
            } else {
                system_log(sprintf('未匹配到任何dat文件名：%s', $peopleUrl));
            }

            return true;
        });
        $multiClient->error(function ($instance) {
            system_log(sprintf('multiClient访问病人画面 curl请求出错：%s %s#%s', $instance->url, $instance->errorCode, $instance->errorMessage));

            return false;
        });

        $downloadStartTime = time();
        $resultChunks = array_chunk($result, $this->concurrentDownloadNum);
        foreach ($resultChunks as $resultChunk) {
            $compTasks = []; // 清空待压缩任务
            foreach ($resultChunk as $item) {
                $multiClient->addGet(
                    sprintf(
                        '%s?layoutNum=%s',
                        $item['peopleUrl'],
                        $item['layoutNum']
                    )
                );
            }
            $dts = time();
            system_log(sprintf('等待中，直到前%d个请求完成，防止请求过于频繁', $size));
            $multiClient->start(); // Blocks until all items in the queue have been processed.
            $multiClient2->start();
            system_log(sprintf('前%d个请求已完成', $size));
            system_log(sprintf('下载耗时：%s', self::formatTimeInterval($dts, time())));

            // 压缩已下载的文件
            system_log('开始压缩已下载的Dat文件');
            $compStartTime = time();
            foreach ($compTasks as $task) {
                list($dir, $layoutNum) = explode('@', $task);
                $nameRegex = sprintf('/(?:%s_\d+|%sn)\.dat/i', $layoutNum, $layoutNum);
                $files = self::lsFiles($dir, $nameRegex);

                try {
                    $zipFile = sprintf('%s%s.zip', $dir, $layoutNum);
                    $zip = new ZipArchive();
                    if ($zip->open($zipFile, ZIPARCHIVE::CREATE) !== true) {
                        throw new \Exception(sprintf('创建压缩文件失败：%s', $zipFile));
                    }

                    foreach ($files as $file) {
                        $zip->addFile($file, basename($file));
                    }
                    $zip->close();

                    // 执行$zip->close()才是真正压缩过程，故只能在这之后删除被压缩的文件，否则找不到要被压缩的文件
                    foreach ($files as $file) {
                        if (file_exists($file)) {
                            if (unlink($file)) {
                                system_log(sprintf('成功删除已压缩文件：%s', $file));
                            } else {
                                system_log(sprintf('删除文件失败：%s', $file));
                            }
                        } else {
                            system_log(sprintf('文件不存在，无法删除：%s', $file));
                        }
                    }
                    system_log(sprintf('成功生成压缩文件：%s', $zipFile));
                    usleep(500000);
                } catch (\Exception $e) {
                    if (isset($zip) && $zip instanceof ZipArchive) {
                        $zip->close();
                    }
                    system_log(sprintf('尝试压缩出错：%s', $e->getMessage()));
                }
            }
            system_log(sprintf('完成一批压缩任务，共耗时%s', self::formatTimeInterval($compStartTime, time())));
        }

        system_log(sprintf('所有文件下载完成，下载过程共耗时%s', self::formatTimeInterval($downloadStartTime, time())));
        system_log(sprintf('所有任务执行完成，共耗时%s', self::formatTimeInterval($startTime, time())));

        // 拜拜了您勒
        self::$client && self::$client->close();
        self::$multiClient && self::$multiClient->close();
        $multiClient2->close();
    }

    /**
     * 列出目录下匹配的文件，如果不指定匹配正则则列出目录下所有文件
     *
     * @param string $directory
     * @param string $nameRegex 匹配文件名的正则
     *
     * @return array
     */
    public static function lsFiles(string $directory, string $nameRegex = '')
    {
        $directory = realpath($directory) . DS;
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $d = dir($directory);
        while (($filename = $d->read()) !== false) {
            $file = $directory . $filename;
            if ($filename !== '.' && $filename !== '..' && !is_dir($file)) {
                if (!$nameRegex || preg_match($nameRegex, $filename)) {
                    $files[] = $file;
                }
            }
        }
        $d->close();

        return $files;
    }

    public function setAllParams()
    {
        global $argv;

        foreach ($argv as $a) {
            if (preg_match('/^-{1,2}(?P<name>\w+)=(?P<val>[^\s]+)$/i', $a, $m)) {
                $this->allParams[$m['name']] = $m['val'];
            }
        }

        return $this;
    }

    public function getParam(string $name, string $defaults = '')
    {
        if (!$this->allParams) {
            $this->setAllParams();
        }
    }

    /**
     * 格式化时间间隔为人类友好时间
     *
     * @param integer $start
     * @param integer $end
     *
     * @return string
     */
    public static function formatTimeInterval($start, $end)
    {
        $val = $end - $start;

        /*if (function_exists('gmdate')) {
            return gmdate('H小时i分钟s秒', $val);
        }*/

        if ($val >= 3600) {
            $h = floor($val / 3600);
            $m = floor(($val / 60) % 60);
            $s = $val % 60;

            return sprintf('%02d小时%02d分钟%02d秒', $h, $m, $s);
        } else if ($val < 3600 && $val >= 60) {
            $m = floor(($val / 60) % 60);
            $s = $val % 60;

            return sprintf('%02d分钟%02d秒', $m, $s);
        } else { // $val < 60
            return sprintf('%02d秒', $val);
        }
    }

    /**
     * @param string $name
     * @param string $url
     *
     * @return mixed|string
     */
    public static function getQuery(string $name, string $url)
    {
        return preg_match(sprintf('/[?&]%s=(?P<val>(?:[^&]|(?!$))+)/i', $name), $url, $m) ? $m['val'] : '';
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
