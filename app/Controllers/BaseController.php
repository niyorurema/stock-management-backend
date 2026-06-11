<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class BaseController extends Controller
{
    protected $helpers = [];
    protected $session;
    protected $db;
    
    protected $request;
    protected $response;
    
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Initialiser les propriétés
        $this->request = $request;
        $this->response = $response;
        
        $this->session = \Config\Services::session();
        $this->db = \Config\Database::connect();
    }
}
