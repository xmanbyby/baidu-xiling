<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xmanbyby\BaiduXiling\BaiduApiClient;

class BaiduApiClientTest
{
    private BaiduApiClient $client;

    public function __construct()
    {
        $options = [
            'api_key' => '你的API Key',
            'secret_key' => '你的Secret Key',
        ];

        $this->client = new BaiduApiClient($options);
    }

    public function testGetAccessToken()
    {
        echo "测试获取 AccessToken...\n";
        $token = $this->client->getAccessToken();
        echo "AccessToken: $token\n\n";
    }

    public function testCreateAndQueryTask()
    {
        echo "测试创建数字人音视频合成任务...\n";

        $taskParams = [
            'templateVideoId' => '你的模板视频ID',
            'driveType' => 'TEXT', // TEXT 或 VOICE
            'text' => '你好，欢迎使用曦灵数字人服务。',
            'ttsParams' => [
                'person' => '1',
                'speed' => '5',
                'volume' => '5',
                'pitch' => '5',
            ],
            // 'inputAudioUrl' => '音频驱动URL（driveType=VOICE时必填）',
            // 'callbackUrl' => '回调地址（可选）',
        ];

        $response = $this->client->createSynthesisTask($taskParams);
        print_r($response);

        $taskId = $response['result']['taskId'] ?? '';
        if ($taskId) {
            echo "任务ID: $taskId\n";

            echo "查询任务状态...\n";
            $status = $this->client->getSynthesisTaskResult($taskId);
            print_r($status);

            echo "轮询等待任务完成...\n";
            $result = $this->client->waitForTaskResult($taskId, [$this->client, 'getSynthesisTaskResult'], 60, 5);
            print_r($result);
        } else {
            echo "任务创建失败，无法查询。\n";
        }
    }

    public function testSubmitAndQueryTtsTask()
    {
        echo "测试提交异步语音合成任务...\n";

        $params = [
            'text' => '这是测试语音合成内容',
            'voice_config' => [
                'voice_id' => '1',
                'speed' => 1.0,
                'pitch' => 1.0,
                'volume' => 1.0,
            ],
            'audio_config' => [
                'format' => 'mp3',
                'sample_rate' => 24000,
            ],
        ];

        $response = $this->client->submitTtsTask($params);
        print_r($response);

        $taskId = $response['task_id'] ?? '';
        if ($taskId) {
            echo "语音任务ID: $taskId\n";

            echo "查询语音任务状态...\n";
            $status = $this->client->queryTtsTask($taskId);
            print_r($status);

            echo "轮询等待语音任务完成...\n";
            $result = $this->client->waitForTaskResult($taskId, [$this->client, 'queryTtsTask'], 30, 3);
            print_r($result);
        } else {
            echo "语音任务提交失败，无法查询。\n";
        }
    }

    public function testUploadFile()
    {
        echo "测试上传文件...\n";
        $filePath = __DIR__ . '/test.mp4'; // 请准备一个测试文件
        $params = [
            // 额外参数，比如 'type' => 'video'
        ];

        try {
            $res = $this->client->uploadFile($filePath, $params);
            print_r($res);
        } catch (\Exception $e) {
            echo "上传文件失败：" . $e->getMessage() . "\n";
        }
    }
}

$test = new BaiduApiClientTest();
$test->testGetAccessToken();
$test->testCreateAndQueryTask();
$test->testSubmitAndQueryTtsTask();
$test->testUploadFile();
