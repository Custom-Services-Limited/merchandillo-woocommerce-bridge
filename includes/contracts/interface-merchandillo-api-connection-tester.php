<?php

interface Merchandillo_Api_Connection_Tester_Interface
{
    /**
     * @return array{ok:bool,code:string,http_status:int}
     */
    public function run(): array;
}
