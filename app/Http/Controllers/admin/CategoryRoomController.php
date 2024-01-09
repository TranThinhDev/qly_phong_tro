<?php

namespace App\Http\Controllers\admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\CategoryRoom;
class CategoryRoomController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = CategoryRoom::all();
        return view('dashboard.categoryroom.index', compact('data'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('dashboard.categoryroom.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        
        $validated = $request->validate([
            'name' => 'required|unique:category_rooms|max:255',
            'description' => 'max:500',
        ], [
            'name.unique' => $this->deleteImagebyPath($request->path_img),
        ]);
        $loaiPhong = new CategoryRoom;
        $loaiPhong->name = $request->name;
        $loaiPhong->image = $request->path_img;
        $loaiPhong->description = $request->description;
        $result = $loaiPhong->save();
        $toast = $this->makeToast($result, 'Thêm thành công', 'Thêm thất bại');
        $data = CategoryRoom::all();
        return view('dashboard.categoryroom.index', compact('data','toast'));
    }
    public function upload(Request $request)
    {
        $image = $request->file('file');
        $imageName = $image->getClientOriginalName();
        $image->move(public_path('images/categoryroom'),$imageName);
        return response()->json(['success'=>$imageName]);
    }
    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        
        $data = CategoryRoom::where('id',$id)->first();
        return view('dashboard.categoryroom.edit', compact('data'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        
        $validated = $request->validate([
            'name' => 'required|max:255',
    
        ]);
        $oldData = CategoryRoom::where('id',$request->id)->first();
        $this->deleteImagebyPath($oldData->image);
        $result = CategoryRoom::where('id',$request->id)->update([
                'name' => $request->name,
                'description' =>$request->description,
                'image' => $request->path_img
        ]);
     
        $toast = $this->makeToast($result, 'Cập nhật thành công', 'Cập nhật thất bại');
        $data = CategoryRoom::all();
        return view('dashboard.categoryroom.index', compact('data','toast'));

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $data = CategoryRoom::where('id', $id)->first();
        $this->deleteImagebyPath($data->image);
        $result = CategoryRoom::where('id', $id)->delete();
        $toast = $this->makeToast($result, 'Xóa thành công', 'Xóa thất bại');
        $data = CategoryRoom::all();
        return view('dashboard.categoryroom.index', compact('data','toast'));

    }
    public function deleteImagebyPath($link)
    {
       $type = CategoryRoom::where('image',$link)->first();
       if($type != null) {
        $filename = $link;
        $path=public_path().'\\images\\categoryroom\\'.$filename;
        if (file_exists($path)) {
            unlink($path);
            return "Tên loại phòng đã tồn tại!";
        }
        return "Tên loại phòng đã tồn tại, Lỗi xóa ảnh";
       }else {
         return "";
       }
       
    }
    public function deleteImage(Request $request)
    {
        
        $filename =  $request->get('filename');
        //ImageUpload::where('filename',$filename)->delete();
        $path=public_path().'\\images\\categoryroom\\'.$filename;
        if (file_exists($path)) {
            unlink($path);
            return response()->json(['success'=>$filename]);
        }
        return response()->json(['error'=>$path]);
    }
}
