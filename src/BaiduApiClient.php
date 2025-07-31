<?php
declare(strict_types=1);

namespace Xmanbyby\BaiduXiling;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class BaiduApiException extends \RuntimeException {}

class BaiduApiClient
{
    protected string $apiKey;
    protected string $secretKey;

    protected ?string $accessToken = null;
    protected int $tokenExpire = 0;

    protected Client $http;

    public function __construct(array $options)
    {
        if (empty($options['api_key']) || empty($options['secret_key'])) {
            throw new \InvalidArgumentException('api_key 和 secret_key 必须提供');
        }

        $this->apiKey = $options['api_key'];
        $this->secretKey = $options['secret_key'];

        $this->http = new Client();
    }

    /**
     * 手动设置 AccessToken 及过期时间（时间戳）
     */
    public function setAccessToken(string $token, int $expireTimestamp): void
    {
        $this->accessToken = $token;
        $this->tokenExpire = $expireTimestamp;
    }

    /**
     * 主动请求并获取新的 AccessToken
     * @return array ['access_token' => string, 'expire' => int 时间戳]
     * @throws RuntimeException|GuzzleException
     */
    public function fetchAccessToken(): array
    {
        $url = 'https://aip.baidubce.com/oauth/2.0/token';

        $response = $this->http->post($url, [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->apiKey,
                'client_secret' => $this->secretKey,
            ],
        ]);

        $data = json_decode((string)$response->getBody(), true);

        if (empty($data['access_token'])) {
            throw new RuntimeException('获取百度 AccessToken 失败: ' . json_encode($data));
        }

        $accessToken = $data['access_token'];
        $expire = time() + (int)$data['expires_in'];

        return [
            'access_token' => $accessToken,
            'expire' => $expire,
        ];
    }

    /**
     * 获取当前缓存的 AccessToken，若无或过期自动刷新
     * 这里不做缓存，只在内存中保留
     * @throws RuntimeException|GuzzleException
     */
    public function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpire > time() + 60) {
            return $this->accessToken;
        }
        $tokenData = $this->fetchAccessToken();
        $this->setAccessToken($tokenData['access_token'], $tokenData['expire']);
        return $this->accessToken;
    }

    /**
     * 发送带鉴权的API请求
     * @throws BaiduApiException
     */
    public function request(string $method, string $url, array $params = [], array $headers = []): array
    {
        $headers = array_merge([
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $headers);

        $options = ['headers' => $headers];

        if (strtolower($headers['Content-Type']) === 'application/json') {
            $options['json'] = $params;
        } else {
            $options['form_params'] = $params;
        }

        try {
            $response = $this->http->request($method, $url, $options);
            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error']) && $data['error']) {
                throw new BaiduApiException($data['error_description'] ?? 'Unknown error');
            }

            return $data;
        } catch (\Throwable $e) {
            throw new BaiduApiException('百度接口请求失败: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 上传文件
     * @throws RuntimeException|BaiduApiException|GuzzleException
     */
    public function uploadFile(string $filePath, array $params = []): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("文件不存在: $filePath");
        }

        $accessToken = $this->getAccessToken();
        $url = "https://aip.baidubce.com/rest/2.0/ai_dh/file/upload?access_token={$accessToken}";

        $multipart = [
            [
                'name'     => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath),
            ]
        ];

        foreach ($params as $key => $value) {
            $multipart[] = [
                'name'     => $key,
                'contents' => $value,
            ];
        }

        $response = $this->http->request('POST', $url, [
            'multipart' => $multipart,
        ]);

        $body = json_decode((string)$response->getBody(), true);

        if (!is_array($body)) {
            throw new RuntimeException('接口响应格式错误');
        }

        return $body;
    }

     /**
     * 创建曦灵数字人音视频合成任务
     *
     * 接口地址：POST https://open.xiling.baidu.com/api/digitalhuman/open/v1/video/submit/fast
     * 注意：driveType=TEXT（文本驱动）时，会在后台调用 TTS 语音合成，
     * 返回类型为视频任务，合成后包含音频与视频资源。
     *
     * 请求参数示例（$params）：
     * [
     *   'templateVideoId' => '底板视频素材文件ID',
     *   'driveType'       => 'TEXT',              // TEXT（文本驱动）或 VOICE（音频驱动）
     *   'text'            => '要合成的文本内容',   // driveType=TEXT 必填，支持 SSML，最大 20000 字符
     *   'ttsParams' => [                            // TTS 合成参数（TEXT 模式下必填）
     *       'person' => '发音人ID',
     *       'speed'  => '5',                         // 语速：0–15，默认 5
     *       'volume' => '5',                         // 音量：0–15，默认 5
     *       'pitch'  => '5'                          // 音调：0–15，默认 5
     *   ],
     *   'inputAudioUrl'  => '驱动音频 URL',       // driveType=VOICE 时必填
     *   'callbackUrl'    => '回调通知地址（选填）'
     * ]
     *
     * 返回示例：
     * [
     *   'code'    => 0,
     *   'success' => true,
     *   'result'  => [
     *       'taskId' => 'xxx'
     *   ],
     *   'requestId' => '...'
     * ]
     *
     * @param array $params
     * @return array 返回响应信息，包含 result.taskId 等数据
     * @throws BaiduApiException
     */
    public function createSynthesisTask(array $params): array
    {
        $url = 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/digital_human/synthesis';

        return $this->request('POST', $url, $params, [
            'Content-Type' => 'application/json'
        ]);
    }

    /**
     * 查询曦灵数字人音视频合成任务结果
     *
     * 接口地址：POST https://aip.baidubce.com/rpc/2.0/ai_custom/v1/digital_human/synthesis/query
     *
     * @param string $taskId 合成任务的 task_id
     * @return array 查询结果，例如：
     * [
     *     'task_status' => 'Success',    // 状态：Success / Failed / Running
     *     'video_url'   => 'https://.../video.mp4', // 成功时的视频下载地址
     *     ...
     * ]
     */
    public function getSynthesisTaskResult(string $taskId): array
    {
        $url = 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/digital_human/synthesis/query';

        $params = ['task_id' => $taskId];

        return $this->request('POST', $url, $params, [
            'Content-Type' => 'application/json'
        ]);
    }




    /**
     * 提交异步语音合成任务（曦灵 TTS）
     * 文档：https://cloud.baidu.com/doc/AI_DH/s/um3ztkf8x
     *
     * @param array $params 包含以下字段（所有字段均为 JSON 格式）：
     * 
     * 必填字段：
     * - text (string)            要合成的文本内容，UTF-8 编码，最长不超过 2000 个汉字
     * - voice_config (array)     发音配置，子字段如下：
     *      - voice_id (string)       发音人 ID，可通过接口获取支持的发音人
     *      - speed (float)           语速，建议范围 0.5 - 2.0，默认 1.0
     *      - pitch (float)           音高，建议范围 0.5 - 2.0，默认 1.0
     *      - volume (float)          音量，建议范围 0.5 - 2.0，默认 1.0
     * 
     * 可选字段：
     * - audio_config (array)     音频输出配置，子字段如下：
     *      - format (string)         音频格式，如 "mp3", "wav"（默认 "mp3"）
     *      - sample_rate (int)       采样率，支持 16000 或 24000（默认 24000）
     * - user_config (array)      用户侧配置，子字段如下：
     *      - user_id (string)        用户自定义 ID，用于回溯或记录业务标识
     * 
     * 示例：
     * [
     *   'text' => '你好，这是一段语音合成测试',
     *   'voice_config' => [
     *       'voice_id' => '1',
     *       'speed' => 1.0,
     *       'pitch' => 1.0,
     *       'volume' => 1.0
     *   ],
     *   'audio_config' => [
     *       'format' => 'mp3',
     *       'sample_rate' => 24000
     *   ],
     *   'user_config' => [
     *       'user_id' => 'your-custom-id'
     *   ]
     * ]
     *
     * @return array 响应内容，如 ["task_id" => "xxx", "log_id" => ...]
     * @throws BaiduApiException
     */
    public function submitTtsTask(array $params): array
    {
        $accessToken = $this->getAccessToken();
        $url = "https://aip.baidubce.com/api/digitalhuman/open/v1/tts/text2audio/submit?access_token={$accessToken}";

        return $this->request('POST', $url, $params, [
            'Content-Type' => 'application/json'
        ]);
    }



    /**
     * 查询异步语音合成任务状态（曦灵 TTS）
     * 文档：https://cloud.baidu.com/doc/AI_DH/s/um3ztkf8x
     *
     * 请求方式：GET
     * 接口地址：/api/digitalhuman/open/v1/tts/text2audio/task
     * 完整请求地址需拼接 access_token 和 task_id
     *
     * @param string $taskId 必填，任务 ID，由 submitTtsTask 接口返回
     * 
     * @return array 响应结果，格式如下：
     * [
     *   'task_id' => 'xxx',
     *   'task_status' => 'Success'|'Running'|'Failed',
     *   'audio_url' => 'https://...mp3', // 若成功时才有
     *   'error_msg' => 'xxx'             // 若失败时才有
     * ]
     *
     * @throws BaiduApiException
     */
    public function queryTtsTask(string $taskId): array
    {
        if (!$taskId) {
            throw new \InvalidArgumentException("task_id 不能为空");
        }

        $accessToken = $this->getAccessToken();
        $url = "https://aip.baidubce.com/api/digitalhuman/open/v1/tts/text2audio/task?access_token={$accessToken}&task_id={$taskId}";

        return $this->request('GET', $url);
    }


    /**
     * 通用任务轮询器（支持音频/视频等任务）
     *
     * @param string   $taskId        任务 ID
     * @param callable $queryCallback 查询函数，格式为 function(string $taskId): array
     * @param int      $timeout       超时时间（秒）
     * @param int      $interval      每次轮询间隔（秒）
     *
     * @return array   成功时返回任务详情
     *
     * @throws RuntimeException|BaiduApiException
     */
    public function waitForTaskResult(string $taskId, callable $queryCallback, int $timeout = 30, int $interval = 3): array
    {
        $startTime = time();
        while (true) {
            $result = $queryCallback($taskId);
                $status = $result['task_status'] ?? '';
            if ($status === 'Success') {
                return $result;
            }
            if ($status === 'Failed') {
                throw new RuntimeException("任务失败，task_id={$taskId}，错误信息：" . json_encode($result, JSON_UNESCAPED_UNICODE));
            }
            if ((time() - $startTime) >= $timeout) {
                throw new RuntimeException("任务超时未完成，task_id={$taskId}");
            }
            sleep($interval);
        }
    }
    

}
