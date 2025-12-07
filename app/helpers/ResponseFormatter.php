<?php
namespace app\helpers;

class ResponseFormatter {
    protected static $response = [
        'meta' => [
            'code' => 200,
            'status' => 'success',
            'message' => null,
        ],
        'data' => null,
    ];

    public static function success($data = null, $message = null)
    {
        self::$response['meta']['message'] = $message;
        self::$response['data'] = $data;

        http_response_code(self::$response['meta']['code']);
        echo json_encode(self::$response);
        exit;
    }

    public static function error($message = null, $code = 400)
    {
        self::$response['meta']['status'] = 'error';
        self::$response['meta']['code'] = $code;
        self::$response['meta']['message'] = $message;

        http_response_code(self::$response['meta']['code']);
        echo json_encode(self::$response);
        exit;
    }
}