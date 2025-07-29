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
        echo "测试创建合成任务...\n";

        $taskParams = [
            // 根据百度官方示例填入参数
            // "script_id" => "xxxx",
            // "video_config" => [...],
            // "audio_config" => [...],
            // "action_config" => [...],
        ];

        $response = $this->client->createSynthesisTask($taskParams);
        print_r($response);

        if (!empty($response['result']['task_id'])) {
            $taskId = $response['result']['task_id'];
            echo "任务ID: $taskId\n";

            echo "查询任务状态...\n";
            $status = $this->client->getSynthesisTaskResult($taskId);
            print_r($status);

            echo "轮询等待任务完成...\n";
            $result = $this->client->waitForSynthesisResult($taskId);
            print_r($result);
        } else {
            echo "任务创建失败，无法查询。\n";
        }
    }

    public function testUploadFile()
    {
        echo "测试上传文件...\n";
        $filePath = __DIR__ . '/test.mp4'; // 请准备一个测试文件
        $params = [
            // 额外参数
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
// $test->testCreateAndQueryTask();
// $test->testUploadFile();
