export const roleLabelMap = {
  admin: '管理员',
  dispatcher: '调度员',
  driver: '司机',
}

export const userStatusLabelMap = {
  active: '启用',
  inactive: '停用',
}

export const taskStatusLabelMap = {
  draft: '草稿',
  assigned: '待接单',
  accepted: '已接单',
  pending: '待调度',
  scheduled: '已排程',
  in_progress: '执行中',
  completed: '已完成',
  cancelled: '已取消',
}

export const dispatchModeLabelMap = {
  single_vehicle_single_order: '单车单订单',
  single_vehicle_multi_order: '单车多订单',
  multi_vehicle_single_order: '多车单订单',
  multi_vehicle_multi_order: '多车多订单',
}

export const vehicleTypeLabelMap = {
  van: '厢式货车',
  flatbed: '平板车',
  truck: '卡车',
  tank: '罐车',
  coldchain: '冷链车',
  tanker: '罐车',
  cold_chain: '冷链车',
}

export const vehicleStatusLabelMap = {
  idle: '空闲',
  busy: '执行中',
  maintenance: '维护中',
}

export const siteTypeLabelMap = {
  pickup: '装货地',
  dropoff: '卸货地',
  both: '装卸一体点',
}

export const getLabel = (map, value) => map[value] || value || '-'
