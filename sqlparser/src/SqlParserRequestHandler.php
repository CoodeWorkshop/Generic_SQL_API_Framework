<?php

require_once __DIR__ . '/SqlGenerator.php';

class SqlParserRequestHandler
{
    public function handle(string $method, string $body, int $contentLength): array
    {
        if ($method !== 'POST') return [405, ['success'=>false,'message'=>'Method not allowed.','error'=>['code'=>'METHOD_NOT_ALLOWED','details'=>[]]]];
        if ($contentLength > 200000 || strlen($body) > 200000) return [413, ['success'=>false,'message'=>'Request body exceeds 200,000 bytes.','error'=>['code'=>'REQUEST_TOO_LARGE','details'=>[]]]];
        try { $input=json_decode($body,true,512,JSON_THROW_ON_ERROR); }
        catch(JsonException){ return [400,['success'=>false,'message'=>'Invalid JSON request.','error'=>['code'=>'INVALID_JSON','details'=>[]]]]; }
        if(!is_array($input)||array_keys($input)!==['sql']||!is_string($input['sql']))return [400,['success'=>false,'message'=>'Request must contain only a string sql property.','error'=>['code'=>'INVALID_REQUEST','details'=>[]]]];
        try { $result=(new SqlGenerator())->generate($input['sql']); return [$result['success']?200:422,$result]; }
        catch(SqlParserException $exception){return [400,['success'=>false,'message'=>'SQL parse error.','analysis'=>['pipeline'=>['parser'=>$exception->getMessage(),'capability'=>'Not started.','mapping'=>'Not started.','validation'=>'Not started.']],'error'=>['code'=>'SQL_PARSE_ERROR','stage'=>'parser','details'=>[['path'=>'sql','position'=>$exception->position,'message'=>$exception->getMessage()]]]]];}
        catch(Throwable){return [500,['success'=>false,'message'=>'SQL parser failed.','error'=>['code'=>'PARSER_ERROR','details'=>[]]]];}
    }
}
