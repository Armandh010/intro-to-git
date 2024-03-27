<?php

namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Model;
use App\Models\ExistenciasLocaciones;
use App\Models\LocacionesMovimientos;
use App\Models\ProductosLocaciones;

class Locaciones extends Model
{
    public $timestamps = true;//guardato automático de updated_at y modified_at
    protected $table = 'locaciones';
    protected $connection = 'sevi_system';

    private static $slp_sii = '';

    public function __construct()
    {
        $this->slp_sii = env('SEV_DB_SII');
    }

    #Obtener el id de la locación por nombre y sucursal
    public static function getIdLocByName($nombre_locacion, $sucursal_id){
        $id = 0;
        $result = Locaciones::where(['nombre'=>$nombre_locacion,'sucursal_id'=>$sucursal_id])->first(['id']);
        if (isset($result->id)) {
            $id = $result->id;
        }
        return $id;
    }

    //Obtener datos generales de locación
    public static function getLocacion($idLocacion)
    {
        $data = Locaciones::leftjoin('locaciones_pasillos','locaciones.locaciones_pasillos_id','=','locaciones_pasillos.id')
                            ->leftjoin('locaciones_numero_estantes','locaciones.locaciones_numero_estantes_id','=','locaciones_numero_estantes.id')
                            ->leftjoin('locaciones_niveles','locaciones.locaciones_niveles_id','=','locaciones_niveles.id')
                            ->leftjoin('locaciones_posiciones','locaciones.locaciones_posiciones_id','=','locaciones_posiciones.id')
                            ->leftjoin('ubicaciones','locaciones.ubicacion_id','=','ubicaciones.id')
                            ->leftjoin('almacenes','locaciones.almacen_id','=','almacenes.id')
                            ->where(['locaciones.id'=>$idLocacion,'locaciones.estatus'=>1])
                            ->first(['locaciones.*','locaciones_pasillos.nombre as pasillo','locaciones_numero_estantes.numero as no_estante','locaciones_niveles.nombre as nivel',
                                    'locaciones_posiciones.nombre as posicion','ubicaciones.nombre as ubicacion','almacenes.nombre as almacen']);

        if(isset($data->id))
        {
            if($data->ubicacion_id != 4)
            {
                //obtener clave ligada a locacion
                $itemLoc = ProductoLocaciones::getItemLoc($idLocacion);

                if(isset($itemLoc->iditem))
                {
                    $data->clave = $itemLoc->code;
                    $data->desc = $itemLoc->description;
                    $data->barcode = $itemLoc->barcode;
                    $data->iditem = $itemLoc->iditem;
                }
                else
                {
                    $data->iditem = 0; 
                }
            }
            else
            {
                $data->iditem = 0;
            }
        }

        return $data;
    }

    //Obtener infrmación de locación
    public static function getData($idLocacion)
    {
        $data = Locaciones::getLocacion($idLocacion);

        if(isset($data->id))
        {
            $data->locacion = $data->nombre;
            
            if($data->volumetria > 0)
            {
                $data->volumetria_porc = Locaciones::getProcVol($idLocacion, $data->ubicacion_id, $data->volumetria);
            }
            else
            {
                $data->volumetria_porc = 0;
            }
            
            if($data->peso > 0)
            {
                $data->peso_porc = Locaciones::getProcPeso($idLocacion, $data->ubicacion_id, $data->peso);
            }
            else
            {
                $data->peso_porc = 0;
            }
            

            $data->volumetria = number_format($data->volumetria,0,'.',',');
            $data->peso = number_format($data->peso,0,'.',',');
        }

        return $data;
    }

    //Obtener productos de locación
    public static function getItemsLocacion($idLocacion)
    {
        $products = ExistenciasLocaciones::leftjoin((new static)->slp_sii.'.tblitem as i','existencias_locaciones.iditem','=','i.iditem')
                                            ->where(['existencias_locaciones.locaciones_id'=>$idLocacion])
                                            ->whereIn('estatus',[1,5])
                                            ->GroupBy(['existencias_locaciones.iditem','existencias_locaciones.ubicacion_id','existencias_locaciones.almacen_id',
                                                        'existencias_locaciones.lote','existencias_locaciones.caducidad','existencias_locaciones.cantidad_corrugado'])
                                            ->orderBy('i.code','asc')
                                            ->get(['existencias_locaciones.iditem','existencias_locaciones.ubicacion_id','existencias_locaciones.almacen_id','existencias_locaciones.lote','i.code','i.description',
                                                   'existencias_locaciones.caducidad','existencias_locaciones.corrugado_no','existencias_locaciones.cantidad_corrugado',DB::raw('SUM(existencias_locaciones.disponible) as disponible')]);


        return $products;
    }

    public static function getExistenciasLote($idLocacion, $iditem, $ubicacion, $almacen, $lote, $caducidad)
    {
        $products = ExistenciasLocaciones::where(['locaciones_id'=>$idLocacion,'iditem'=>$iditem,'ubicacion_id'=>$ubicacion,'almacen_id'=>$almacen,'lote'=>$lote,'caducidad'=>$caducidad])
                                            ->whereIn('estatus',[1,5])
                                            ->get(['id','corrugado_no','cantidad_corrugado','disponible','estatus']);

        return $products;
    }

    public static function getTotalLote($idLocacion, $iditem, $ubicacion, $almacen, $lote, $caducidad)
    {
        $products = ExistenciasLocaciones::where(['locaciones_id'=>$idLocacion,'iditem'=>$iditem,'ubicacion_id'=>$ubicacion,'almacen_id'=>$almacen,'lote'=>$lote,'caducidad'=>$caducidad])
                                            ->whereIn('estatus',[1,5])
                                            ->sum('disponible');


        return $products;
    }

    //Obtener porcentaje de volumetria de locación
    public static function getProcVol($idLocacion, $ubicacionId, $volTotLoc)
    {
        $volTot = str_replace(',', "", $volTotLoc);
        
        $vol_proc = 0;
        $products = Locaciones::getItemsLocacion($idLocacion);

        if($products->Count() > 0)
        {
            $tot_vol_use = 0;

            foreach ($products as $key => $value) 
            {
                $cantidad = 0;
                $vol_item = 0;

                if($ubicacionId == 4 && $value->disponible > 0 && $value->cantidad_corrugado > 0)
                {
                    //Obtener cantidad de corrugados
                    $qty = (float)$value->disponible / (float)$value->cantidad_corrugado;
                    //Redondear valor a siguiente entero
                    $cantidad = ceil($qty);
                    //Obtener vol de corrugado item
                    $vol_item = Producto::getVolCorrugado($value->iditem);
                }
                else
                {
                    //Obtener cantidad
                    $cantidad = $value->disponible;
                    //Obtener vol de corrugado item
                    $vol_item = Producto::getVolItem($value->iditem);
                }

                //Calcula volumen de reg
                $vol_reg = (float)$vol_item * (float)$cantidad;

                $tot_vol_use = (float)$tot_vol_use + (float)$vol_reg;
            }

            if($tot_vol_use > 0)
            {
                $vol_proc = ((float)$tot_vol_use / (float)$volTot)*100;
            }
            else
            {
                $vol_proc = 0;
            }
            
        }

        return number_format($vol_proc,0,'.','');
    }

    //Obtener porcentaje de peso de locación
    public static function getProcPeso($idLocacion, $ubicacionId, $pesoTot)
    {
        $peso_proc = 0;
        $products = Locaciones::getItemsLocacion($idLocacion);

        if($products->Count() > 0)
        {
            $tot_peso_use = 0;

            foreach ($products as $key => $value) 
            {
                $cantidad = 0;
                $peso_item = 0;

                if($ubicacionId == 4 && $value->disponible > 0 && $value->cantidad_corrugado > 0)
                {
                    //Obtener cantidad de corrugados
                    $qty = (float)$value->disponible / (float)$value->cantidad_corrugado;
                    //Redondear valor a siguiente entero
                    $cantidad = ceil($qty);
                    //Obtener vol de corrugado item
                    $peso_item = Producto::getPesoCorrugado($value->iditem);
                }
                else
                {
                    //Obtener cantidad
                    $cantidad = $value->disponible;
                    //Obtener vol de corrugado item
                    $peso_item = Producto::getPesoItem($value->iditem);
                }

                //Calcula volumen de reg
                $peso_reg = (float)$peso_item * (float)$cantidad;

                $tot_peso_use = (float)$tot_peso_use + (float)$peso_reg;
            }

            if($tot_peso_use > 0)
            {
                $peso_proc = ((float)$tot_peso_use / (float)$pesoTot)*100;
            }
            else
            {
                $peso_proc = 0;
            }
            
        }

        return number_format($peso_proc,0,'.','');
    }

    //Obtener volumen en uso actual
    public static function getVolUsoLocacion($idLoc, $ubicacionId)
    {
        $tot_vol_use = 0;
        $products = Locaciones::getItemsLocacion($idLoc);
        
        //Obtener volumen ocupado en locacion destino
        if($products->Count() > 0)
        {
            foreach ($products as $key => $value) 
            {
                $cantidad = 0;
                $vol_item = 0;

                if($ubicacionId == 4 && $value->cantidad_corrugado > 0)
                {
                    //Obtener cantidad de corrugados
                    $qty = (float)$value->disponible / (float)$value->cantidad_corrugado;
                    //Redondear valor a siguiente entero
                    $cantidad = ceil($qty);
                    //Obtener vol de corrugado item
                    $vol_item = Producto::getVolCorrugado($value->iditem);
                }
                else
                {
                    //Obtener cantidad
                    $cantidad = $value->disponible;
                    //Obtener vol de corrugado item
                    $vol_item = Producto::getVolItem($value->iditem);
                }

                //Calcula volumen de reg
                $vol_reg = (float)$vol_item * (float)$cantidad;

                $tot_vol_use = (float)$tot_vol_use + (float)$vol_reg;
            }
        }

        return $tot_vol_use;
    }

    //Obtener peso en uso actual
    public static function getPesoUsoLocacion($idLocacion, $ubicacionId)
    {
        $tot_peso_use = 0;
        $products = Locaciones::getItemsLocacion($idLocacion);
        
        //Obtener peso ocuapdo por productos en locación
        if($products->Count() > 0)
        {
            foreach ($products as $key => $value) 
            {
                $cantidad = 0;
                $peso_item = 0;

                if($ubicacionId == 4 && $value->cantidad_corrugado > 0)
                {
                    //Obtener cantidad de corrugados
                    $qty = (float)$value->disponible / (float)$value->cantidad_corrugado;
                    //Redondear valor a siguiente entero
                    $cantidad = ceil($qty);
                    //Obtener vol de corrugado item
                    $peso_item = Producto::getPesoCorrugado($value->iditem);
                }
                else
                {
                    //Obtener cantidad
                    $cantidad = $value->disponible;
                    //Obtener vol de corrugado item
                    $peso_item = Producto::getPesoItem($value->iditem);
                }

                //Calcula volumen de reg
                $peso_reg = (float)$peso_item * (float)$cantidad;

                $tot_peso_use = (float)$tot_peso_use + (float)$peso_reg;
            }
        }

        return $tot_peso_use;
    }

    //Valida si locacion tiene espacio disponible
    public static function validaVolLocacion($idExistencia, $idLocacion, $ubicacionId)
    {
        $disp = 0;
        $mover = 0;
        $vol_proc = 0;
        $dataExist = ExistenciasLocaciones::where('id',$idExistencia)->first();
        $dataLoc = Locaciones::where(['id'=>$idLocacion])->first();

        //Obtener volumen de la existencias a mover
        $cantidad_i = 0;
        $vol_item_i = 0;

        if($ubicacionId == 4 && $value->cantidad_corrugado > 0)
        {
            //Obtener cantidad de corrugados
            $qtyi = (float)$dataExist->disponible / (float)$dataExist->cantidad_corrugado;
            //Redondear valor a siguiente entero
            $cantidad_i = ceil($qtyi);
            //Obtener vol de corrugado item
            $vol_item_i = Producto::getVolCorrugado($dataExist->iditem);
        }
        else
        {
            //Obtener cantidad
            $cantidad_i = $dataExist->disponible;
            //Obtener vol de corrugado item
            $vol_item_i = Producto::getVolItem($dataExist->iditem);
        }

        //Calcula volumen de reg
        $vol_item_f = (float)$vol_item_i * (float)$cantidad_i;

        //Obtener volumen en uso actual de la locación
        $tot_vol_use = Locaciones::getVolUsoLocacion($idLocacion, $ubicacionId);

        $disp = (float)$dataLoc->volumetria - (float)$tot_vol_use;
        
        if($disp >= $vol_item_f)
        {
            $mover = 1;
        }

        return $mover;
    }

    //Valida si locacion tiene espacio disponible
    public static function validaPesoLocacion($idExistencia, $idLocacion, $ubicacionId)
    {
        $mover = 0;
        $vol_proc = 0;
        $tot_peso_use = 0;
        
        $dataExist = ExistenciasLocaciones::where('id',$idExistencia)->first();
        $dataLoc = Locaciones::where(['id'=>$idLocacion])->first();

        //Obtener peso de la existencias a mover
        $cantidad_i = 0;
        $peso_item_i = 0;

        if($ubicacionId == 4 && $value->cantidad_corrugado > 0)
        {
            //Obtener cantidad de corrugados
            $qtyi = (float)$dataExist->disponible / (float)$dataExist->cantidad_corrugado;
            //Redondear valor a siguiente entero
            $cantidad_i = ceil($qtyi);
            //Obtener peso de corrugado item
            $peso_item_i = Producto::getPesoCorrugado($dataExist->iditem);
        }
        else
        {
            //Obtener cantidad
            $cantidad_i = $dataExist->disponible;
            //Obtener peso de corrugado item
            $peso_item_i = Producto::getPesoItem($dataExist->iditem);
        }

        //Calcula peso de reg
        $peso_item_f = (float)$peso_item_i * (float)$cantidad_i;

        //Obtener peso usado actual en locación
        $tot_peso_use = Locaciones::getPesoUsoLocacion($idLocacion, $ubicacionId);

        //peso disponible
        $Pesodisp = (float)$dataLoc->peso - (float)$tot_peso_use;
        
        if($Pesodisp >= $peso_item_f)
        {
            $mover = 1;
        }

        return $mover;
    }

    //Obtener volumen que ocupara el movimineto
    public static function getVolMov($idMov, $ubicacion_id)
    {
        $tot_vol_use = 0;
        $products = LocacionesMovimientos::getAllItemsMovimiento($idMov);
        $mov = LocacionesMovimientos::getInfMov($idMov);
        
        //Obtener volumen ocupado en locacion destino
        if($products->Count() > 0)
        {
            foreach ($products as $key => $value) 
            {
                $cantidad = 0;
                $vol_item = 0;

                if($ubicacion_id == 4 && $value->cantidad_corrugado > 0 && $value->disponible > 0)
                {
                    //Obtener cantidad de corrugados
                    $qty = (float)$value->disponible / (float)$value->cantidad_corrugado;
                    //Redondear valor a siguiente entero
                    $cantidad = ceil($qty);
                    //Obtener vol de corrugado item
                    $vol_item = Producto::getVolCorrugado($value->iditem);
                }
                else
                {
                    //Obtener cantidad
                    $cantidad = $value->disponible;
                    //Obtener vol de corrugado item
                    $vol_item = Producto::getVolItem($value->iditem);
                }

                //Calcula volumen de reg
                $vol_reg = (float)$vol_item * (float)$cantidad;

                $tot_vol_use = (float)$tot_vol_use + (float)$vol_reg;
            }
        }

        return $tot_vol_use;
    }

    //Obtener peso que ocupara el movimiento
    public static function getPesoMov($idMov, $ubicacion_id)
    {
        $tot_peso_use = 0;
        $products = LocacionesMovimientos::getAllItemsMovimiento($idMov);
        $mov = LocacionesMovimientos::getInfMov($idMov);
        
        //Obtener peso ocuapdo por productos en locación
        if($products->Count() > 0)
        {
            foreach ($products as $key => $value) 
            {
                $cantidad = 0;
                $peso_item = 0;

                if($ubicacion_id == 4 && $value->cantidad_corrugado > 0 && $value->disponible > 0)
                {
                    //Obtener cantidad de corrugados
                    $qty = (float)$value->disponible / (float)$value->cantidad_corrugado;
                    //Redondear valor a siguiente entero
                    $cantidad = ceil($qty);
                    //Obtener vol de corrugado item
                    $peso_item = Producto::getPesoCorrugado($value->iditem);
                }
                else
                {
                    //Obtener cantidad
                    $cantidad = $value->disponible;
                    //Obtener vol de corrugado item
                    $peso_item = Producto::getPesoItem($value->iditem);
                }

                //Calcula volumen de reg
                $peso_reg = (float)$peso_item * (float)$cantidad;

                $tot_peso_use = (float)$tot_peso_use + (float)$peso_reg;
            }
        }

        return $tot_peso_use;
    }

    //Valida si mov, se puede aplicar, locacion tiene peso y volumen disponible
    public static function validaMovLocacion($idMov, $idLocDest)
    {
        $val = array();
        $mov = LocacionesMovimientos::getInfMov($idMov);
        $ubicacion_id = $mov->ubicacion_id;

        $pesoMov = Locaciones::getPesoMov($idMov, $ubicacion_id);
        $volMov = Locaciones::getVolMov($idMov, $ubicacion_id);

        $volLocDest = Locaciones::getVolUsoLocacion($idLocDest, $ubicacion_id);
        $pesoLocDest = Locaciones::getPesoUsoLocacion($idLocDest, $ubicacion_id);

        $loc_data = Locaciones::where(['id'=>$idLocDest])->first(['volumetria','peso']);

        $volumetria = str_replace(',', "", $loc_data->volumetria);
        $peso = str_replace(',', "", $loc_data->peso);

        $loc_disp_vol   = (float)$volumetria - (float)$volLocDest;
        $loc_disp_peso  = (float)$peso - (float)$pesoLocDest;

        if((float)$loc_disp_vol >= (float)$volMov)
        {
            $val[] = 1;
        }

        if((float)$loc_disp_peso >= (float)$pesoMov)
        {
            $val[] = 2;
        }

        return $val;
    }

    public static function getPasilloLoc($idLoc)
    {
        $data = Locaciones::leftjoin('locaciones_pasillos','locaciones.locaciones_pasillos_id','=','locaciones_pasillos.id')
                            ->where(['locaciones.id'=>$idLoc,'locaciones.estatus'=>1])
                            ->first(['locaciones.*','locaciones_pasillos.nombre as pasillo']);

        return $data;
    }
}