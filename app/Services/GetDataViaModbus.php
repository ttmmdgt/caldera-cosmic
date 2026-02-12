<?php

namespace App\Services;

use ModbusTcpClient\Composer\Read\ReadRegistersBuilder;
use ModbusTcpClient\Network\NonBlockingClient;
use ModbusTcpClient\Network\BinaryStreamConnection;
use ModbusTcpClient\Packet\ModbusFunction\WriteMultipleRegistersRequest;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleCoilRequest;
use ModbusTcpClient\Packet\ModbusFunction\WriteSingleRegisterRequest;
use ModbusTcpClient\Packet\ResponseFactory;
use ModbusTcpClient\Utils\Types;

class GetDataViaModbus
{
    private $timeout = 2;

    public function getDataReadInputRegisters($ip_address, $port, $unit_id = 1, $address, $name)
    {
        try {
            $request = ReadRegistersBuilder::newReadInputRegisters("tcp://{$ip_address}:{$port}", $unit_id)
                ->int16($address, $name)
                ->build();
            $response = (new NonBlockingClient(['readTimeoutSec' => $this->timeout]))
                ->sendRequests($request)
                ->getData();
            return $response[$name];
        } catch (\Throwable $th) {
            return null;
        }
    }

    public function getDataReadHoldingRegisters($ip_address, $port, $unit_id = 1, $address, $name)
    {
        $request = ReadRegistersBuilder::newReadHoldingRegisters("tcp://{$ip_address}:{$port}", $unit_id)
            ->int16($address, $name)
            ->build();
        $response = (new NonBlockingClient(['readTimeoutSec' => $this->timeout]))
            ->sendRequests($request)
            ->getData();
        return $response[$name];
    }
}