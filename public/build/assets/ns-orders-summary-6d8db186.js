import"./bootstrap-8be442b1.js";import{_ as f,n as p}from"./currency-f8bd78d1.js";import{_ as x}from"./_plugin-vue_export-helper-c27b6911.js";import{r as c,o,c as n,a as s,t,f as d,e as _,F as b,b as v,n as u}from"./runtime-core.esm-bundler-b48de70a.js";const y={name:"ns-orders-summary",data(){return{orders:[],subscription:null,hasLoaded:!1}},mounted(){this.hasLoaded=!1,this.subscription=Dashboard.recentOrders.subscribe(a=>{this.hasLoaded=!0,this.orders=a})},methods:{__:f,nsCurrency:p},unmounted(){this.subscription.unsubscribe()}},g={id:"ns-orders-summary",class:"flex flex-auto flex-col shadow rounded-lg overflow-hidden"},k={class:"p-2 flex title items-center justify-between border-b"},w={class:"font-semibold"},C={class:"head flex-auto flex-col flex h-64 overflow-y-auto ns-scrollbar"},L={key:0,class:"h-full flex items-center justify-center"},j={key:1,class:"h-full flex items-center justify-center flex-col"},O=s("i",{class:"las la-grin-beam-sweat text-6xl"},null,-1),B={class:"text-sm"},N={class:"text-lg font-semibold"},V={class:"flex -mx-2"},z={class:"px-1"},D={class:"text-semibold text-xs"},F=s("i",{class:"lar la-user-circle"},null,-1),R=s("div",{class:"divide-y-4"},null,-1),S={class:"px-1"},E={class:"text-semibold text-xs"},W=s("i",{class:"las la-clock"},null,-1);function q(a,i,A,G,l,r){const h=c("ns-close-button"),m=c("ns-spinner");return o(),n("div",g,[s("div",k,[s("h3",w,t(r.__("Recents Orders")),1),s("div",null,[d(h,{onClick:i[0]||(i[0]=e=>a.$emit("onRemove"))})])]),s("div",C,[l.hasLoaded?_("",!0):(o(),n("div",L,[d(m,{size:"8",border:"4"})])),l.hasLoaded&&l.orders.length===0?(o(),n("div",j,[O,s("p",B,t(r.__("Well.. nothing to show for the meantime.")),1)])):_("",!0),(o(!0),n(b,null,v(l.orders,e=>(o(),n("div",{key:e.id,class:u([e.payment_status==="paid"?"paid-order":"other-order","border-b single-order p-2 flex justify-between"])},[s("div",null,[s("h3",N,t(r.__("Order"))+" : "+t(e.code),1),s("div",V,[s("div",z,[s("h4",D,[F,s("span",null,t(e.user.username),1)])]),R,s("div",S,[s("h4",E,[W,s("span",null,t(e.created_at),1)])])])]),s("div",null,[s("h2",{class:u([e.payment_status==="paid"?"paid-currency":"unpaid-currency","text-xl font-bold"])},t(r.nsCurrency(e.total)),3)])],2))),128))])])}const M=x(y,[["render",q]]);export{M as default};
