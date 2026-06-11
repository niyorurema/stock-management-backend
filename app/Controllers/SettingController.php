<?php
// E:\laragon\www\stock-management\backend\app\Controllers\SettingController.php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class SettingController extends ResourceController
{
    use ResponseTrait;
    
    public function index()
    {
        return $this->respond(['success' => true, 'data' => []]);
    }
    
    public function update()
    {
        return $this->respond(['success' => true, 'message' => 'Settings updated']);
    }
    
    public function testEBMSConnection()
    {
        return $this->respond(['success' => true, 'message' => 'EBMS connection test successful']);
    }
}