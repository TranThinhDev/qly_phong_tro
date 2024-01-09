@extends('layouts.app')

@section('style')
@endsection

@section('content')
 <!-- breadcrumb start -->
 <section class="breadcrumb-section p-0">
    <img src="../assets/images/inner-background.jpg" class="bg-img img-fluid" alt="">
    <div class="container">
        <div class="breadcrumb-content">
            <div>
                <h2>Trang giới thiệu</h2>
                <nav aria-label="breadcrumb" class="theme-breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('trang_chu') }}">Trang chủ</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Giới thiệu</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>
</section>
<!-- breadcrumb end -->

<!-- About us section start -->
<section class="about-main ratio_36">
    <div class="container">
        <div class="row">
            <div class="col">
                <div class="title-2">
                    <h2>PHONGTRO20 là gì ?</h2>
                    <p class="font-roboto">Phòng trọ 20. Số 20 ở đây đại diện cho tỉnh Thái Nguyên, Đây là một trang web tìm trọ dành cho những người tìm trọ ở Thái Nguyên. PhHONGTRO20 cũng là sản phẩm được tạo ra để làm bài báo cáo thực tập cơ sở của mình. Còn mình là ai thì các bạn hãy kéo xuống dưới nha!!!</p>
                </div>
                <div class="user-about">
                    <div class="row">
                        <div class="col-xl-5 col-lg-7">
                            <div class="about-content">
                                <h3>Tại sao lại có PHONGTRO20 ?</h3>
                                <p class="font-roboto">Sau ba năm học tập tại Thái Nguyên mình thấy việc tìm trọ của các bạn sinh viên ở đây khá là khó khăn. Đó chính là lý do mình muốn tạo nên trang web này.</p>
                            </div>
                        </div>
                        <div class="col-xl-7 map-image col-lg-5">
                            <img src="../assets/images/about/map.png" class="img-fluid bg-img" alt="">
                            
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- About us section end -->

<!-- testimonial section start -->
<section class="about-testimonial">
    <div class="container">
        <div class="row">
            <div class="col">
                <div class="title-2">
                    <h2>Ai đã tạo ra PHONGTRO20</h2>
                    <p class="font-roboto">Một anh chàng coder xây dựng trang web này bằng cả trái tim ❤️</p>
                </div>
                <div class="testimonial-4">
                    <div class="modern-client row">
                        <div class="col-lg-6">
                            <div class="img-testimonial">
                                <div>
                                    <div class="img-left">
                                        <img src="{{ asset('images/about/coder_1.jpg') }}" alt="" class="img-fluid">
                                    </div>
                                </div>
                                <div>
                                    <div class="img-left">
                                        <img src="{{ asset('images/about/coder_2.jpg') }}" alt="" class="img-fluid">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-5 col-lg-6 col-md-9 col-sm-10">
                            <div class="right-height">
                                <div class="comment-right">
                                    <div>
                                        <div class="media">
                                            <div class="media-body">
                                                <a href="https://www.facebook.com"><h3 class="d-flex">Nguyễn Trung<span
                                                        class="label-heart color-4 ms-2"><i
                                                            class="fas fa-heart"></i></span></h3>
                                                        </a>
                                            </div>
                                            
                                        </div>
                                        <h6>PHP Develop</h6>
                                        <p class="font-roboto">Xin chào mọi người.Mình là Nguyễn Thành Trung, mình đang là một nhân viên lập trình website tại TP Hồ Chí Minh. Mình rất vui khi các bạn ghé thăm một trang web do mình tạo ra. Cảm ơn mọi người rất nhiều !!! </p>
                                        <span class="font-roboto">FB: Nguyễn Thành Trung</span>
                                    </div>
                                    <div>
                                        <div class="media">
                                            <div class="media-body">
                                                <a href="#"><h3 class="d-flex">Trung Nguyễn <span
                                                        class="label-heart color-4 ms-2"><i
                                                            class="fas fa-heart"></i></span></h3>
                                                        </a>
                                            </div>
                                        </div>
                                        <h6>Coder vẫn còn lơ tơ mơ</h6>
                                        <p class="font-roboto">Đây là sản phẩm đầu tiên do bản thân mình làm ra. Mình đã rất cố gắng làm nó thật hoàn chỉnh.Tuy nhiên, Lần đầu thì không thể nào mà chuyên nghiệp được vì vậy trang web sẽ còn khá nhiều lỗi. Nếu các bạn gặp bất kì lỗi nào hoặc có ý kiến đóng góp. Đừng ngận ngại góp ý cho mình nhé </p>
                                        <span class="font-roboto">nguyenthanhtrung@gmail.com</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- testimonial section end -->
@endsection

@section('modal')
@endsection

@section('js')
@endsection
