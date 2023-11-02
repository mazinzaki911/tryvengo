<?php

namespace App\Http\Controllers;

use App\Models\Lineitem;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

class OrderController extends Controller
{

    public function allOrders(){

        $orders=Order::paginate(30);
        return view('orders.index',compact('orders'));
    }


    public function shopifyOrders($next = null){

        $shop=Auth::user();
        $orders = $shop->api()->rest('GET', '/admin/api/orders.json', [
            'limit' => 250,
            'page_info' => $next
        ]);

        if ($orders['errors'] == false) {
            if (count($orders['body']->container['orders']) > 0) {
                foreach ($orders['body']->container['orders'] as $order) {
                    $order = json_decode(json_encode($order));
                    $this->singleOrder($order,$shop);
                }
            }
            if (isset($orders['link']['next'])) {

                $this->shopifyOrders($orders['link']['next']);
            }
        }
        return Redirect::tokenRedirect('home', ['notice' => 'Orders Sync Successfully']);


    }
    public function singleOrder($order, $shop)
    {

        if($order->financial_status!='refunded' && $order->cancelled_at==null  ) {

            $newOrder = Order::where('shopify_id', $order->id)->where('shop_id', $shop->id)->first();
            if ($newOrder == null) {
                $newOrder = new Order();
            }
            $newOrder->shopify_id = $order->id;
            $newOrder->email = $order->email;
            $newOrder->order_number = $order->name;

            if (isset($order->shipping_address)) {
                $newOrder->shipping_name = $order->shipping_address->name;
                $newOrder->address1 = $order->shipping_address->address1;
                $newOrder->address2 = $order->shipping_address->address2;
                $newOrder->phone = $order->shipping_address->phone;
                $newOrder->city = $order->shipping_address->city;
                $newOrder->zip = $order->shipping_address->zip;
                $newOrder->province = $order->shipping_address->province;
                $newOrder->country = $order->shipping_address->country;
            }
            $newOrder->financial_status = $order->financial_status;
            $newOrder->fulfillment_status = $order->fulfillment_status;
            if (isset($order->customer)) {
                $newOrder->first_name = $order->customer->first_name;
                $newOrder->last_name = $order->customer->last_name;
                $newOrder->customer_phone = $order->customer->phone;
                $newOrder->customer_email = $order->customer->email;
                $newOrder->customer_id = $order->customer->id;
            }
            $newOrder->shopify_created_at = date_create($order->created_at)->format('Y-m-d h:i:s');
            $newOrder->shopify_updated_at = date_create($order->updated_at)->format('Y-m-d h:i:s');
            $newOrder->tags = $order->tags;
            $newOrder->note = $order->note;
            $newOrder->total_price = $order->total_price;
            $newOrder->currency = $order->currency;

            $newOrder->subtotal_price = $order->subtotal_price;
            $newOrder->total_weight = $order->total_weight;
            $newOrder->taxes_included = $order->taxes_included;
            $newOrder->total_tax = $order->total_tax;
            $newOrder->currency = $order->currency;
            $newOrder->total_discounts = $order->total_discounts;
            $newOrder->shop_id = $shop->id;
            $newOrder->save();
            foreach ($order->line_items as $item) {
                $new_line = Lineitem::where('shopify_id', $item->id)->where('order_id', $newOrder->id)->where('shop_id', $shop->id)->first();
                if ($new_line == null) {
                    $new_line = new Lineitem();
                }
                $new_line->shopify_id = $item->id;
                $new_line->shopify_product_id = $item->product_id;
                $new_line->shopify_variant_id = $item->variant_id;
                $new_line->title = $item->title;
                $new_line->quantity = $item->quantity;
                $new_line->sku = $item->sku;
                $new_line->variant_title = $item->variant_title;
                $new_line->title = $item->title;
                $new_line->vendor = $item->vendor;
                $new_line->price = $item->price;
                $new_line->requires_shipping = $item->requires_shipping;
                $new_line->taxable = $item->taxable;
                $new_line->name = $item->name;
                $new_line->properties = json_encode($item->properties, true);
                $new_line->fulfillable_quantity = $item->fulfillable_quantity;
                $new_line->fulfillment_status = $item->fulfillment_status;
                $new_line->order_id = $newOrder->id;
                $new_line->shop_id = $shop->id;
                $new_line->shopify_order_id = $order->id;
                $new_line->save();
            }
        }
    }


    public function SendOrderDelivery($id)
    {
        $shop = Auth::user();
        $order = Order::find($id);
        $setting=Setting::first();
        if($order){

            $url = 'https://tryvengo.com/api/place-ecomerce-order';
            $pickupEstimateTime = now()->addHours(4);
//            dd($pickupEstimateTime->format('d/m/Y h:i A'));
            $data = [
                'email' =>$setting->email,
                'password' =>$setting->password,
                'pick_up_address_id' => 37,
                'item_type' => 'ds',
                'item_price' => $order->total_price,
                'payment_mode' => 2,
                'pickup_estimate_time_type' => 0,
                'pickup_estimate_time' => $pickupEstimateTime->format('d/m/Y h:i A'),
                'recipient_name' => $order->shipping_name,
                'recipient_mobile' => $order->phone,
                'address_area' => 'Bayan',
                'address_block_number' => $order->address2,
                'address_street' => $order->address1,
            ];

            // Build the cURL request
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false),
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => http_build_query($data), // Convert data to a query string
                CURLOPT_HTTPHEADER => array(
                    'email: ' . $setting->email,
                    'password: ' . $setting->password,
                    'Content-Type: application/x-www-form-urlencoded',
                ),
            ));

            // Execute the cURL request
            $response = curl_exec($curl);

            // Close the cURL session
            curl_close($curl);

            // Decode the JSON response
            $responseData = json_decode($response, true);

            if ($responseData['status']==1) {
                $deliveryId = $responseData['delivery_id'];
                $invoiceId = $responseData['invoice_id'];

                $order->delivery_id=$deliveryId;
                $order->invoice_id=$invoiceId;
                $order->status=1;
                $order->tryvengo_status='Pending';
                $order->save();
                return Redirect::tokenRedirect('home', ['notice' => 'Order Pushed to Tryvengo Successfully']);
                // Now, $deliveryId and $invoiceId contain the respective values
            }

        }

    }


    public function OrdersFilter(Request $request){

        $shop=Auth::user();
        $orders=Order::query();
        $orders_count=Order::where('shop_id',$shop->id)->count();
        if($request->orders_filter!=null) {
            $orders = $orders->where('order_number', 'like', '%' . $request->orders_filter . '%')->orWhere('shipping_name', 'like', '%' . $request->orders_filter . '%');
        }
//        $orders=$orders->where('shop_id',$shop->id)->orderBy('id', 'DESC')->paginate(20);
        $orders=$orders->where('shop_id',$shop->id)->orderBy('id', 'DESC')->paginate(30);
        return view('orders.index',compact('orders','request','shop','orders_count'));
    }



    public function TrackOrder(){
        $setting=Setting::first();
        $url = 'https://tryvengo.com/api/track-order';
        $data = [
            'email' =>'orders@mubkhar.com',
            'password' => 'Mubkv9Qh@1',
           'invoice_id'=>'14874213'
        ];

        // Build the cURL request
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false),
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query($data), // Convert data to a query string
            CURLOPT_HTTPHEADER => array(
                'email: ' . $setting->email,
                'password: ' . $setting->password,
                'Content-Type: application/x-www-form-urlencoded',
            ),
        ));

        // Execute the cURL request
        $response = curl_exec($curl);

        // Close the cURL session
        curl_close($curl);

        // Decode the JSON response
        $responseData = json_decode($response, true);
        dd($responseData);
        if($responseData['status']==1){

//           $order->tryvengo_status=$responseData['order_data']['order_status'];
//        $order->save();
        }

    }
}
