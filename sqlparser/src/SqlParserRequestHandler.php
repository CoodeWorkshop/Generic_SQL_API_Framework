<?php

require_once __DIR__ . '/SqlGenerator.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/ExceptionHandler.php';

class SqlParserRequestHandler
{
    public function handle(string $method, string $body, int $contentLength): array
    {
        if ($method !== 'POST') return [405, Response::errorPayload('Method not allowed.','METHOD_NOT_ALLOWED')];
        if ($contentLength > 200000 || strlen($body) > 200000) return [413, Response::errorPayload('Request body is too large.','REQUEST_TOO_LARGE')];
        try { $input=json_decode($body,true,512,JSON_THROW_ON_ERROR); }
        catch(JsonException){ return [400,Response::errorPayload('Invalid JSON request.','INVALID_JSON')]; }
        if(!is_array($input)||array_keys($input)!==['sql']||!is_string($input['sql']))return [400,Response::errorPayload('Request must contain only a string sql property.','INVALID_REQUEST')];
        try { $result=(new SqlGenerator())->generate($input['sql']); return [$result['success']?200:422,$result]; }
        catch(SqlParserException $exception){
            $sql=$input['sql'];
            $before=substr($sql,0,max(0,$exception->position));
            $line=substr_count($before,"\n")+1;
            $lastNewline=strrpos($before,"\n");
            $column=$exception->position-($lastNewline===false?-1:$lastNewline);
            $payload=Response::errorPayload('SQL parse error.','SQL_PARSE_ERROR',[['path'=>'sql','position'=>$exception->position,'line'=>$line,'column'=>$column,'message'=>$exception->getMessage()]]);
            $payload['analysis']=['pipeline'=>['parser'=>$exception->getMessage(),'capability'=>'Not started.','mapping'=>'Not started.','validation'=>'Not started.']];
            $payload['error']['stage']='parser';
            return [400,$payload];
        }
        catch(Throwable $exception){ExceptionHandler::report($exception,'sql_parser.exception');return [500,Response::errorPayload('SQL parser failed.','PARSER_ERROR')];}
    }
}
