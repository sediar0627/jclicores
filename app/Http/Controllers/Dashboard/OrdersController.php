<?php

/**
 * NexoPOS Controller
 *
 * @since  1.0
 **/

namespace App\Http\Controllers\Dashboard;

use App\Classes\Hook;
use App\Classes\Output;
use App\Crud\CustomerCrud;
use App\Crud\OrderCrud;
use App\Crud\OrderInstalmentCrud;
use App\Crud\PaymentTypeCrud;
use App\Events\OrderAfterPrintedEvent;
use App\Exceptions\NotAllowedException;
use App\Fields\OrderPaymentFields;
use App\Http\Controllers\DashboardController;
use App\Http\Requests\OrderPaymentRequest;
use App\Models\Order;
use App\Models\OrderInstalment;
use App\Models\OrderPayment;
use App\Models\OrderRefund;
use App\Models\PaymentType;
use App\Print\OrderItemPrint;
use App\Services\DateService;
use App\Services\Options;
use App\Services\OrdersService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;
use Illuminate\Support\Str;

class OrdersController extends DashboardController
{
    private $paymentTypes;

    public function __construct(
        private OrdersService $ordersService,
        private Options $optionsService,
        protected DateService $dateService
    ) {
        $this->middleware( function ( $request, $next ) {
            /**
             * @todo must be refactored
             */
            $this->paymentTypes = PaymentType::orderBy( 'priority', 'asc' )
                ->active()
                ->get()
                ->map( function ( $payment, $index ) {
                    $payment->selected = $index === 0;

                    return $payment;
                } );

            return $next( $request );
        } );
    }

    public function create( Request $request )
    {
        return $this->ordersService->create( $request->post() );
    }

    public function updateOrder( Order $id, Request $request )
    {
        return $this->ordersService->create( $request->post(), $id );
    }

    public function addProductToOrder( $order_id, Request $request )
    {
        $order = $this->ordersService->getOrder( $order_id );

        return $this->ordersService->addProducts( $order, $request->input( 'products' ) );
    }

    public function getOrderPaymentReceipt( OrderPayment $orderPayment, Request $request )
    {
        $order = $orderPayment->order;
        $order->load( 'customer' );
        $order->load( 'products' );
        $order->load( 'shipping_address' );
        $order->load( 'billing_address' );
        $order->load( 'user' );

        $orderPayment->load( 'order' );

        return View::make( 'pages.dashboard.orders.templates.payment-receipt', [
            'payment' => $orderPayment,
            'order' => $order,
            'paymentTypes' => collect( $this->paymentTypes )->mapWithKeys( function ( $payment ) {
                return [ $payment[ 'identifier' ] => $payment[ 'label' ] ];
            } ),
            'ordersService' => app()->make( OrdersService::class ),
            'billing' => ( new CustomerCrud )->getForm()[ 'tabs' ][ 'billing' ][ 'fields' ],
            'shipping' => ( new CustomerCrud )->getForm()[ 'tabs' ][ 'shipping' ][ 'fields' ],
            'title' => sprintf( __( 'Payment Receipt &mdash; %s' ), $order->code ),
        ] );
    }

    public function listOrders()
    {
        Hook::addAction(
            'ns-crud-footer',
            fn( Output $output ) => $output
                ->addView( 'pages.dashboard.orders.footer' )
        );

        return OrderCrud::table();
    }

    public function getPOSOrder( $order_id )
    {
        return $this->ordersService->getOrder( $order_id );
    }

    /**
     * get order products
     *
     * @param int order id
     * @return array or product
     */
    public function getOrderProducts( $id )
    {
        return $this->ordersService->getOrderProducts( $id );
    }

    public function getOrderPayments( $id )
    {
        return $this->ordersService->getOrderPayments( $id );
    }

    public function deleteOrderProduct( $orderId, $productId )
    {
        $order = $this->ordersService->getOrder( $orderId );

        return $this->ordersService->deleteOrderProduct( $order, $productId );
    }

    public function getOrders( ?Order $id = null )
    {
        if ( $id instanceof Order ) {
            $id->load( 'customer' );
            $id->load( 'payments' );
            $id->load( 'shipping_address' );
            $id->load( 'billing_address' );
            $id->load( 'products.unit' );
            $id->load( 'refundedProducts.unit', 'refundedProducts.product', 'refundedProducts.orderProduct' );

            return $id;
        }

        if ( request()->query( 'limit' ) ) {
            return Order::limit( request()->query( 'limit' ) )
                ->get();
        }

        return Order::with( 'customer' )->get();
    }

    public function showPOS()
    {
        /**
         * let's inject the necessary dependency
         * for being able to manage orders.
         */
        Hook::addAction(
            'ns-dashboard-footer',
            fn( Output $output ) => $output
                ->addView( 'pages.dashboard.orders.footer' )
        );

        return View::make( 'pages.dashboard.orders.pos', [
            'title' => sprintf(
                __( 'POS' ),
                ns()->option->get( 'ns_store_name', 'NexoPOS' )
            ),
            'orderTypes' => collect( $this->ordersService->getTypeOptions() )
                ->filter( function ( $type, $label ) {
                    return in_array( $label, ns()->option->get( 'ns_pos_order_types' ) ?: [] );
                } ),
            'options' => Hook::filter( 'ns-pos-options', [
                'ns_pos_printing_document' => ns()->option->get( 'ns_pos_printing_document', 'receipt' ),
                'ns_orders_allow_partial' => ns()->option->get( 'ns_orders_allow_partial', 'no' ),
                'ns_orders_allow_unpaid' => ns()->option->get( 'ns_orders_allow_unpaid', 'no' ),
                'ns_pos_order_types' => ns()->option->get( 'ns_pos_order_types', [] ),
                'ns_pos_order_sms' => ns()->option->get( 'ns_pos_order_sms', 'no' ),
                'ns_pos_sound_enabled' => ns()->option->get( 'ns_pos_sound_enabled', 'yes' ),
                'ns_pos_quick_product' => ns()->option->get( 'ns_pos_quick_product', 'no' ),
                'ns_pos_quick_product_default_unit' => ns()->option->get( 'ns_pos_quick_product_default_unit', 0 ),
                'ns_pos_price_with_tax' => ns()->option->get( 'ns_pos_price_with_tax', 'no' ),
                'ns_pos_unit_price_ediable' => ns()->option->get( 'ns_pos_unit_price_ediable', 'no' ),
                'ns_pos_printing_enabled_for' => ns()->option->get( 'ns_pos_printing_enabled_for', 'only_paid_orders' ),
                'ns_pos_registers_enabled' => ns()->option->get( 'ns_pos_registers_enabled', 'no' ),
                'ns_pos_idle_counter' => ns()->option->get( 'ns_pos_idle_counter', 0 ),
                'ns_pos_disbursement' => ns()->option->get( 'ns_pos_disbursement', 'no' ),
                'ns_customers_default' => ns()->option->get( 'ns_customers_default', false ),
                'ns_pos_vat' => ns()->option->get( 'ns_pos_vat', 'disabled' ),
                'ns_pos_tax_group' => ns()->option->get( 'ns_pos_tax_group', null ),
                'ns_pos_tax_type' => ns()->option->get( 'ns_pos_tax_type', false ),
                'ns_pos_printing_gateway' => ns()->option->get( 'ns_pos_printing_gateway', 'default' ),
                'ns_pos_show_quantity' => ns()->option->get( 'ns_pos_show_quantity', 'no' ) === 'no' ? false : true,
                'ns_pos_new_item_audio' => ns()->option->get( 'ns_pos_new_item_audio', '' ),
                'ns_pos_complete_sale_audio' => ns()->option->get( 'ns_pos_complete_sale_audio', '' ),
                'ns_pos_numpad' => ns()->option->get( 'ns_pos_numpad', 'default' ),
                'ns_pos_allow_wholesale_price' => ns()->option->get( 'ns_pos_allow_wholesale_price', 'no' ) === 'yes' ? true : false,
                'ns_pos_allow_decimal_quantities' => ns()->option->get( 'ns_pos_allow_decimal_quantities', 'no' ) === 'yes' ? true : false,
                'ns_pos_force_autofocus' => ns()->option->get( 'ns_pos_force_autofocus', 'no' ) === 'yes' ? true : false,
            ] ),
            'urls' => [
                'sale_printing_url' => Hook::filter( 'ns-pos-printing-url', ns()->url( '/dashboard/orders/receipt/{id}?dash-visibility=disabled&autoprint=true' ) ),
                'orders_url' => ns()->route( 'ns.dashboard.orders' ),
                'dashboard_url' => ns()->route( 'ns.dashboard.home' ),
                'categories_url' => ns()->route( 'ns.dashboard.products.categories.create' ),
                'registers_url' => ns()->route( 'ns.dashboard.registers-create' ),
                'order_type_url' => ns()->route( 'ns.dashboard.settings', [ 'settings' => 'pos?tab=features' ] ),
            ],
            'paymentTypes' => $this->paymentTypes,
        ] );
    }

    public function orderInvoice( Order $order )
    {
        $optionsService = app()->make( Options::class );

        $order->load( 'customer' );
        $order->load( 'products' );
        $order->load( 'shipping_address' );
        $order->load( 'billing_address' );
        $order->load( 'user' );
        $order->load( 'taxes' );

        $order->products = Hook::filter( 'ns-receipt-products', $order->products );
        $order->paymentStatus = $this->ordersService->getPaymentLabel( $order->payment_status );
        $order->deliveryStatus = $this->ordersService->getPaymentLabel( $order->delivery_status );

        return View::make( 'pages.dashboard.orders.templates.invoice', [
            'order' => $order,
            'options' => $optionsService->get(),
            'billing' => ( new CustomerCrud )->getForm()[ 'tabs' ][ 'billing' ][ 'fields' ],
            'shipping' => ( new CustomerCrud )->getForm()[ 'tabs' ][ 'shipping' ][ 'fields' ],
            'title' => sprintf( __( 'Order Invoice &mdash; %s' ), $order->code ),
        ] );
    }

    public function orderRefundReceipt( OrderRefund $refund )
    {
        $refund->load( 'order.customer', 'order.refundedProducts', 'order.refunds.author', 'order.shipping_address', 'order.billing_address', 'order.user' );
        $refund->load( 'refunded_products.product', 'refunded_products.unit' );

        $refund->refunded_products = Hook::filter( 'ns-refund-receipt-products', $refund->refunded_products );

        return View::make( 'pages.dashboard.orders.templates.refund-receipt', [
            'refund' => $refund,
            'ordersService' => app()->make( OrdersService::class ),
            'billing' => ( new CustomerCrud )->getForm()[ 'tabs' ][ 'billing' ][ 'fields' ],
            'shipping' => ( new CustomerCrud )->getForm()[ 'tabs' ][ 'shipping' ][ 'fields' ],
            'title' => sprintf( __( 'Order Refund Receipt &mdash; %s' ), $refund->order->code ),
        ] );
    }

    public function mapPaymentTypes()
    {
        return collect( $this->paymentTypes )->mapWithKeys( function ( $payment ) {
            return [ $payment[ 'identifier' ] => $payment[ 'label' ] ];
        } );
    }

    public function orderReceipt( Order $order )
    {
        $order->load( 'customer' );
        $order->load( 'products' );
        $order->load( 'shipping_address' );
        $order->load( 'billing_address' );
        $order->load( 'user' );

        return View::make( 'pages.dashboard.orders.templates.receipt', [
            'order' => $order,
            'title' => sprintf( __( 'Order Receipt &mdash; %s' ), $order->code ),
            'optionsService' => $this->optionsService,
            'ordersService' => $this->ordersService,
            'paymentTypes' => $this->mapPaymentTypes(),
        ] );
    }

    public function construirConsultaPos( $order )
    {
        $order = $this->ordersService->getOrder( $order );

        $data = [
            'mesa' => $order->title ?? 'N/A',
            'id' => $order->id,
            'fecha' => date('d/m/Y H:i:s'),
            'mesero' => $order->customer?->first_name ?? 'N/A',
            'subtotal' => $order->total,
        ];

        foreach ($order->combinedProducts as $key => $product) {
            $data['producto_'.$key] = implode(',', [
                $product->name,
                $product->product_type != 'dynamic' ? $product->quantity : $product->rate,
                intval($product->total_price),
                $product->product_type != 'dynamic' ? 0 : 1
            ]);
        }

        if( $order->payment_status == Order::PAYMENT_PAID || $order->payment_status == Order::PAYMENT_PARTIALLY ){
            $data[$order->payment_status == Order::PAYMENT_PAID ? 'cambio' : 'pendiente'] = abs( $order->change );
        }

        $paymentTypes = $this->mapPaymentTypes();

        $payments = $order->payments;

        foreach ( $payments as $key => $payment ) {
            $identificador = ($payment['identifier'] ?? $key);
            $data['pago_'.$paymentTypes[$identificador]] = intval($payment['value']);
        }

        $query = http_build_query($data);

        $host = env('LOCALHOST_URL', 'http://localhost:8000');

        return redirect($host.'/impresion/pos?'.$query);
    }

    public function imprimirPos( )
    {
        $data = request()->all();

        $productos = [];

        $productosKeys = array_filter(array_keys($data), function($key) {
            return strpos($key, 'producto_') !== false;
        });

        foreach ($productosKeys as $key) {
            $producto = explode(',', $data[$key]);
            $productos[] = [
                'nombre' => $producto[0],
                'cantidad' => $producto[1],
                'total' => $producto[2],
                'es_dinamico' => (bool) $producto[3],
            ];
        }

        $paymentTypes = $this->mapPaymentTypes();

        $pagos = [];
        
        $pagosKeys = array_filter(array_keys($data), function($key) {
            return strpos($key, 'pago_') !== false;
        });

        foreach ($pagosKeys as $key) {
            $tipo_pago = str_replace('pago_', '', $key);

            $pagos[] = [
                'tipo' => $paymentTypes[$tipo_pago] ?? 'Pago',
                'valor' => $data[$key],
            ];
        }

        $nombreImpresora = "POS-58";
        $connector = new WindowsPrintConnector($nombreImpresora);
        $impresora = new Printer($connector);

        $impresora->setJustification(Printer::JUSTIFY_LEFT);
        $impresora->setFont(Printer::FONT_B);
        $impresora->setTextSize(2, 1);

        $impresora->text("JC Licores\n");

        $impresora->setTextSize(1, 1);
        $impresora->text("Andres Riascos                            \n");
        $impresora->text("Nit. 1084450150                           \n");
        $impresora->text("Calle 18 # 2 - 50                         \n");
        $impresora->text("Telefono +57 3157594115                   \n");
        $impresora->text("Regimen común                             \n");
        
        $impresora->feed(1);
        
        $impresora->text("Mesa: ".$data['mesa']."\n");
        $impresora->text("Id: #".$data['id']."\n");
        $impresora->text("Fecha: ".$data['fecha']."\n");
        $impresora->text("Mesero: ".$data['mesero']."\n");

        $impresora->text("------------------------------------------\n");
        $impresora->text("PRODUCTO x CANTIDAD                  TOTAL\n");
        $impresora->text("------------------------------------------\n");

        foreach ($productos as $product) {
            (new OrderItemPrint(
                $product['nombre'],
                $product['cantidad'],
                $product['total'],
                $impresora,
                !$product['es_dinamico']
            ))->print();
        }

        $impresora->text("------------------------------------------\n");

        $impresora->setTextSize(2, 1);

        (new OrderItemPrint(
            'SUBTOTAL',
            -1,
            $data['subtotal'],
            $impresora
        ))->print(21);


        if( isset($data['cambio']) || isset($data['pendiente']) ){
            $impresora->setTextSize(1, 1);

            $impresora->text("------------------------------------------\n");

            $impresora->setTextSize(2, 1);

            (new OrderItemPrint(
                isset($data['cambio']) ? 'CAMBIO' : 'PENDIENTE',
                -1,
                abs( isset($data['cambio']) ? $data['cambio'] : $data['pendiente'] ),
                $impresora
            ))->print(21);
        }

        $impresora->setTextSize(1, 1);

        if(count($pagos) > 0){
            $impresora->text("------------------------------------------\n");
            $impresora->text("TIPO DE PAGO                         VALOR\n");
            $impresora->text("------------------------------------------\n");
        }

        foreach ( $pagos as $payment ) {
            (new OrderItemPrint(
                $payment['tipo'],
                -1,
                $payment['valor'],
                $impresora
            ))->print();
        }

        $impresora->feed(3);

        $impresora->close();

        return "<script>window.close();</script>";
    }

    // public function orderPos( $order )
    // {
    //     return response()->json( [ 'status' => 'success' ] );

    //     $order = $this->ordersService->getOrder( $order );

    //     $paymentTypes = $this->mapPaymentTypes();

    //     $nombreImpresora = "POS-58";
    //     $connector = new WindowsPrintConnector($nombreImpresora);
    //     $impresora = new Printer($connector);

    //     $impresora->setJustification(Printer::JUSTIFY_LEFT);
    //     $impresora->setFont(Printer::FONT_B);
    //     $impresora->setTextSize(2, 1);

    //     $impresora->text("JC Licores\n");

    //     $impresora->setTextSize(1, 1);
    //     $impresora->text("Andres Riascos                            \n");
    //     $impresora->text("Nit. 1084450150                           \n");
    //     $impresora->text("Calle 18 # 2 - 50                         \n");
    //     $impresora->text("Telefono +57 3157594115                   \n");
    //     $impresora->text("Regimen común                             \n");
        
    //     $impresora->feed(1);
        
    //     $impresora->text("Mesa: ".$order->title."\n");
    //     $impresora->text("Id: #".$order->id."\n");
    //     $impresora->text("Fecha: ".date('d/m/Y H:i:s')."\n");
    //     $impresora->text("Mesero: ".$order->customer?->first_name."\n");

    //     $impresora->text("------------------------------------------\n");
    //     $impresora->text("PRODUCTO x CANTIDAD                  TOTAL\n");
    //     $impresora->text("------------------------------------------\n");

    //     $order->combinedProducts->each(function($product) use (&$impresora) {
    //         (new OrderItemPrint(
    //             $product->name,
    //             $product->product_type == 'dynamic' ? $product->rate : $product->quantity,
    //             $product->total_price,
    //             $impresora,
    //             $product->product_type == 'dynamic' ? false : true
    //         ))->print();
    //     });

    //     $impresora->text("------------------------------------------\n");

    //     $impresora->setTextSize(2, 1);

    //     (new OrderItemPrint(
    //         'SUBTOTAL',
    //         -1,
    //         $order->total,
    //         $impresora
    //     ))->print(21);

    //     if( $order->payment_status == Order::PAYMENT_PAID || $order->payment_status == Order::PAYMENT_PARTIALLY ){
    //         $impresora->setTextSize(1, 1);

    //         $impresora->text("------------------------------------------\n");

    //         $impresora->setTextSize(2, 1);

    //         (new OrderItemPrint(
    //             $order->payment_status == Order::PAYMENT_PAID ? 'CAMBIO' : 'PENDIENTE',
    //             -1,
    //             abs( $order->change ),
    //             $impresora
    //         ))->print(21);
    //     }

    //     $impresora->setTextSize(1, 1);

    //     $payments = $order->payments;

    //     if($payments->count() > 0){
    //         $impresora->text("------------------------------------------\n");
    //         $impresora->text("TIPO DE PAGO                         VALOR\n");
    //         $impresora->text("------------------------------------------\n");
    //     }

    //     foreach ( $payments as $payment ) {
    //         (new OrderItemPrint(
    //             $paymentTypes[ $payment[ 'identifier' ] ] ?? __( 'Pago' ),
    //             -1,
    //             $payment[ 'value' ],
    //             $impresora
    //         ))->print();
    //     }

    //     $impresora->feed(3);

    //     $impresora->close();

    //     return "<script>window.close();</script>";
    // }

    public function voidOrder( Order $order, Request $request )
    {
        return $this->ordersService->void( $order, $request->input( 'reason' ) );
    }

    public function deleteOrder( Order $order )
    {
        return $this->ordersService->deleteOrder( $order );
    }

    public function getSupportedPayments()
    {
        return ( new OrderPaymentFields )->get();
    }

    /**
     * Will perform a payment on a specific order
     *
     * @param  Request $request
     * @return array
     */
    public function addPayment( Order $order, OrderPaymentRequest $request )
    {
        return $this->ordersService->makeOrderSinglePayment( [
            'identifier' => $request->input( 'identifier' ),
            'value' => $request->input( 'value' ),
        ], $order );
    }

    public function makeOrderRefund( Order $order, Request $request )
    {
        return $this->ordersService->refundOrder( $order, $request->all() );
    }

    public function printOrder( Order $order, $doc = 'receipt' )
    {
        OrderAfterPrintedEvent::dispatch( $order, $doc );

        return [
            'status' => 'success',
            'message' => __( 'The printing event has been successfully dispatched.' ),
        ];
    }

    public function listInstalments()
    {
        return OrderInstalmentCrud::table();
    }

    public function getOrderInstalments( Order $order )
    {
        return $order->instalments;
    }

    public function updateInstalment( Order $order, OrderInstalment $instalment, Request $request )
    {
        return $this->ordersService->updateInstalment(
            $order,
            $instalment,
            $request->input( 'instalment' )
        );
    }

    public function deleteInstalment( Order $order, OrderInstalment $instalment )
    {
        if ( (int) $order->id !== (int) $instalment->order_id ) {
            throw new NotAllowedException( __( 'There is a mismatch between the provided order and the order attached to the instalment.' ) );
        }

        return $this->ordersService->deleteInstalment( $order, $instalment );
    }

    public function createInstalment( Order $order, Request $request )
    {
        return $this->ordersService->createInstalment( $order, $request->input( 'instalment' ) );
    }

    public function markInstalmentAs( Order $order, OrderInstalment $instalment )
    {
        if ( (int) $order->id !== (int) $instalment->order_id ) {
            throw new NotAllowedException( __( 'There is a mismatch between the provided order and the order attached to the instalment.' ) );
        }

        return $this->ordersService->markInstalmentAsPaid( $order, $instalment );
    }

    public function payInstalment( Order $order, OrderInstalment $instalment, Request $request )
    {
        if ( (int) $order->id !== (int) $instalment->order_id ) {
            throw new NotAllowedException( __( 'There is a mismatch between the provided order and the order attached to the instalment.' ) );
        }

        return $this->ordersService->markInstalmentAsPaid( $order, $instalment, $request->input( 'payment_type' ) );
    }

    /**
     * Will change the order processing status
     *
     * @return string json response
     */
    public function changeOrderProcessingStatus( Request $request, Order $order )
    {
        return $this->ordersService->changeProcessingStatus( $order, $request->input( 'process_status' ) );
    }

    /**
     * Will change the order processing status
     *
     * @return string json response
     */
    public function changeOrderDeliveryStatus( Request $request, Order $order )
    {
        return $this->ordersService->changeDeliveryStatus( $order, $request->input( 'delivery_status' ) );
    }

    public function listPaymentsTypes()
    {
        return PaymentTypeCrud::table();
    }

    public function createPaymentType()
    {
        return PaymentTypeCrud::form();
    }

    public function updatePaymentType( PaymentType $paymentType )
    {
        return PaymentTypeCrud::form( $paymentType );
    }

    public function getOrderProductsRefunded( Request $request, Order $order )
    {
        return $this->ordersService->getOrderRefundedProducts( $order );
    }

    public function getOrderRefunds( Request $request, Order $order )
    {
        return $this->ordersService->getOrderRefunds( $order );
    }
}
