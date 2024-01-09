<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Room;
use App\Models\CategoryRoom;
use App\Models\User;
use App\Models\districts;
use Redirect;
use App\Models\BookingInformation;
use App\Models\Notification;
use App\Models\CommentRoom;
class RoomController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $districts = districts::where('province_code', 19)->get();
        $category = CategoryRoom::all();
        return view('frontend.room.create', compact('category', 'districts'));
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
       
        $data = Room::where('id', $id)->first();
        $room_list = Room::where('id', '!=', $id)->where('status', 1)->take(4)->get();
        return view('frontend.room.show', compact('room_list'))->with('data', $data);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $user = Auth::user();
        $room = Room::where('id', $id)->where('chutro_id', $user->id)->first();
        $districts = districts::where('province_code', 19)->get();
        $category = CategoryRoom::all();
        return view('frontend.room.edit', compact('category', 'districts', 'room'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $room = Room::where('id', $id)->first();
   
        $this->deleteImagesByName($room->main_img, 'main_room');
        foreach (json_decode($room->list_img) as $key => $value) {
            $this->deleteImagesByName($value, 'multi_room');
        }
        $result = Room::where('id', $id)->delete();
        $user = Auth::user();
        $data = User::where('id', $user->id)->first();
        $toast = $this->makeToast($result, 'Xóa bài thành công', 'Xóa bài thất bại');
        return view('frontend.home', compact('toast', 'data'));
    }
    public function demoRoom($id)
    {
        $user = Auth::user();
        $data = Room::where('id', $id)->where('chutro_id', $user->id)->where('status', null)->first();
        return view('frontend.room.demo')->with('data', $data);
    }
    public function publish($id)
    {
        $result = Room::where('id', $id)->update([
            'status' => 1
        ]);
        $user = Auth::user();
        $data = User::where('id', $user->id)->first();
        $toast = $this->makeToast($result, 'Đăng bài thành công', 'Đăng bài thất bại');
        return Redirect::back()->with(['toast' => $toast ]);
    }
    public function changeStatusRoom($id)
    {
        $room = Room::where('id', $id)->first();
        $result = Room::where('id', $id)->update([
            'status' => $room->status == 1 ? 0: 1,
        ]);
        $toast = $this->makeToast($result, 'Cập nhật thành công', 'Cập nhật thất bại');
        return Redirect::back()->with(['toast' => $toast ]);
    }
    public function sendBookingRoom(Request $request, $id)
    {
        $validate = $request->validate([
            'message' => 'required|max:500',
            'phone' => 'required',
            'name' => 'required',
        ]);
        
        $result = BookingInformation::create([
            'rooms_id' => $id,
            'message' => $request->message,
            'email' => $request->email,
            'name' => $request->name,
            'phone' => $request->phone,
            
        ]);
        if($result) {
            $url = route('booking.show', ['booking' =>$id]);
            $room = Room::where('id', $id)->first();
            $notification = Notification::create([
                'user_id' => $room->chutro_id,
                'title' => "Bạn nhận được một yêu cầu đặt phòng",
                'link' => $url,
                'status' => 0
            ]);
        }
        $toast = $this->makeToast($result, 'Gửi thông tin thành công', 'Gửi thông tin thất bại');
        return Redirect::back()->with(['toast' => $toast ]);
    }
    public function comment(Request $request, $id)
    {
        $request->validate([
            'content' =>'string|max:500|required'
        ]);
        $author_id = Auth::user()->id;
        $result = CommentRoom::create([
            'rooms_id' => $id,
            'author_id' => $author_id,
            'content' => $request->content
        ]);
        if($result) {
            $user_id = Room::where('id', $id)->first()->User->id;
            $title = "Phòng trọ của bạn có một bình luận mới";
            $route = 'Room_show';
            $element = [$id];
            $room = Room::where('id', $id)->first();
            $notification = $this->MakeNotification($user_id, $title, $route, $element);
        }
        $toast = $this->makeToast($result, 'Bình luận đã được gửi', 'Lỗi, Thử lại');
        return Redirect::back()->with(['toast' => $toast ]);
    }
}
