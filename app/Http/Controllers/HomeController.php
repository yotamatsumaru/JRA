<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        // ログイン/ゲスト問わずダッシュボードを表示
        // (ダッシュボードはゲスト閲覧可能)
        return redirect()->route('dashboard');
    }
}
