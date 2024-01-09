<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Redirect;
use Carbon\Carbon;
class ManagerAccount extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = User::all();
        $now = Carbon::now();
        foreach ($user as $key => $value) {
            if($value->block_at != null) {
                $day_block = $now->diffInDays($value->block_at);
                if($value->status == 0 && $day_block > 30) {
                    User::where('id', $value->id)->delete();
                }
            }
            
        }
        return view('dashboard.account.index', compact('user'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
       $user = User::where('id',$id)->first();
       return view('dashboard.account.edit', compact('user'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'name' => 'required|max:255',
            'Zalo' => 'numeric|digits:10',
            'Facebook' => 'url',
            'role' => 'required|numeric'
        ]);
         $result = User::where('id', $id)->update([
            'name' => $request->name,
            'role' => $request->role,
            'Zalo' => $request->Zalo,
            'Facebook' => $request->Facebook,
        ]);
           $this->MakeNotification($id,"Tài khoản của bạn được cập nhật thông tin bởi quản trị viên với lý do: ".$request->ly_do,'user.index', []);
           $toast = $this->makeToast($result, 'Cập nhật thành công', 'Cập nhật thất bại');
           return Redirect::route('account.index')->with(['toast' => $toast ]);
    }
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
    public function block(string $id)
    {
        $user = User::where('id',$id)->first();
        if($user->status == 1) {
            $result = User::where('id', $id)->update([
                 'status' => 0,
                 'block_at' => Carbon::now()
                ]);
            $toast = $this->makeToast($result, 'Chặn thành công, Tài khoản sẽ tự động xóa sau 30 ngày bị chặn', 'Chặn thất bại');
            return Redirect::route('account.index')->with(['toast' => $toast ]);
        }else {
            $result = User::where('id', $id)->update([ 'status' => 1,]);
            $toast = $this->makeToast($result, 'Đã bỏ chặn', 'Lỗi, thử lại');
            return Redirect::route('account.index')->with(['toast' => $toast ]);
        }
    }
    public function InputNotification($id)
    {
        $user = User::where('id',$id)->first();
        return view('dashboard.account.sendNotification', compact('user'));
    }
    public function SendNotification(Request $request, $id)
    {
        $validated = $request->validate([
            'message' => 'required|max:100'
        ]);
        $title = "Quản trị viên: ".$request->message;
        $result = $this->makeNotification($id,$title,'user.index');
        $toast = $this->makeToast($result, 'Đã gửi thông báo', 'Lỗi, thử lại');
        return Redirect::route('account.index')->with(['toast' => $toast ]);
    }
    
}
