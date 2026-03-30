<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { UploadFilled } from '@element-plus/icons-vue'
import api from '../../services/api'
import { getLabel, taskStatusLabelMap } from '../../utils/labels'
import { exportAoaSheetsToXlsx, parseSpreadsheetFile } from '../../utils/spreadsheet'

const tableRef = ref()
const prePlanOrders = ref([])
const selectedOrders = ref([])
const cargoCategories = ref([])
const sites = ref([])
const vehicles = ref([])
const filterForm = reactive({
  keyword: '',
  status: '',
  audit_status: '',
  is_locked: '',
  cargo_category_id: '',
  trace_type: '',
})

const loadingOrders = ref(false)
const loadingMeta = ref(false)
const loadingSites = ref(false)
const loadingVehicles = ref(false)

const createDialogVisible = ref(false)
const batchCreateDialogVisible = ref(false)
const importDialogVisible = ref(false)
const splitDialogVisible = ref(false)
const editDialogVisible = ref(false)
const manualDispatchDialogVisible = ref(false)
const detailDialogVisible = ref(false)

const creating = ref(false)
const editing = ref(false)
const dispatching = ref(false)
const auditing = ref(false)
const managingOrder = ref(false)
const importing = ref(false)
const splitting = ref(false)
const detailLoading = ref(false)

const currentEditId = ref(null)
const detailOrder = ref(null)

const createFormRef = ref()
const editFormRef = ref()

const batchCreatePayloadText = ref('')
const importFile = ref(null)
const importResult = ref(null)
const splitTargetOrder = ref(null)
const splitPartCount = ref(2)
const splitParts = ref([])

const createForm = reactive({
  cargo_category_id: null,
  client_name: '',
  pickup_site_id: null,
  pickup_address: '',
  pickup_contact_name: '',
  pickup_contact_phone: '',
  dropoff_site_id: null,
  dropoff_address: '',
  dropoff_contact_name: '',
  dropoff_contact_phone: '',
  cargo_weight_kg: null,
  cargo_volume_m3: null,
  freight_calc_scheme: '',
  freight_unit_price: null,
  freight_trip_count: 1,
  actual_delivered_weight_kg: null,
  loss_allowance_kg: 0,
  loss_deduct_unit_price: null,
  expected_pickup_at: '',
  expected_delivery_at: '',
})

const editForm = reactive({
  cargo_category_id: null,
  client_name: '',
  pickup_site_id: null,
  pickup_address: '',
  pickup_contact_name: '',
  pickup_contact_phone: '',
  dropoff_site_id: null,
  dropoff_address: '',
  dropoff_contact_name: '',
  dropoff_contact_phone: '',
  cargo_weight_kg: null,
  cargo_volume_m3: null,
  freight_calc_scheme: '',
  freight_unit_price: null,
  freight_trip_count: 1,
  actual_delivered_weight_kg: null,
  loss_allowance_kg: 0,
  loss_deduct_unit_price: null,
  expected_pickup_at: '',
  expected_delivery_at: '',
  status: '',
})

const manualDispatchForm = reactive({
  vehicle_id: null,
  estimated_distance_km: null,
  estimated_fuel_l: null,
  estimated_duration_min: null,
})

const formRules = {
  cargo_category_id: [{ required: true, message: '请选择货品分类', trigger: 'change' }],
  client_name: [{ required: true, message: '请输入客户名称', trigger: 'blur' }],
  pickup_address: [{ required: true, message: '请输入装货地', trigger: 'blur' }],
  dropoff_address: [{ required: true, message: '请输入卸货地', trigger: 'blur' }],
}

const statusTypeMap = {
  pending: 'info',
  scheduled: 'primary',
  in_progress: 'warning',
  completed: 'success',
  cancelled: 'danger',
}

const editableStatusSet = new Set(['pending', 'scheduled', 'in_progress'])
const dispatchableStatusSet = new Set(['pending', 'scheduled'])
const auditStatusLabelMap = {
  pending_approval: '待审核',
  approved: '已审核',
  rejected: '已驳回',
}

const auditStatusTypeMap = {
  pending_approval: 'warning',
  approved: 'success',
  rejected: 'danger',
}

const mergeCompareKeys = [
  { key: 'cargo_category_id', label: '货品分类' },
  { key: 'client_name', label: '客户' },
  { key: 'pickup_address', label: '装货地' },
  { key: 'dropoff_address', label: '卸货地' },
  { key: 'pickup_contact_name', label: '装货联系人' },
  { key: 'pickup_contact_phone', label: '装货联系电话' },
  { key: 'dropoff_contact_name', label: '收货联系人' },
  { key: 'dropoff_contact_phone', label: '收货联系电话' },
  { key: 'audit_status', label: '审核状态' },
]

const freightSchemeLabelMap = {
  by_weight: '按重量',
  by_volume: '按体积',
  by_trip: '按趟',
}

const historyActionLabelMap = {
  dispatcher_create: '调度创建',
  dispatcher_batch_create: '批量创建',
  dispatcher_update: '调度编辑',
  dispatcher_lock: '锁单',
  dispatcher_unlock: '解锁',
  dispatcher_void: '作废',
  dispatcher_split_create: '拆单生成子单',
  dispatcher_split_source_voided: '拆单作废原单',
  dispatcher_merge_create: '并单生成新单',
  dispatcher_merge_source_voided: '并单作废来源单',
  dispatcher_audit_approve: '审核通过',
  dispatcher_audit_reject: '审核驳回',
  customer_submit: '客户提报',
  customer_update: '客户修改',
  customer_resubmit: '客户重提',
}

const freightSchemeOptions = [
  { label: '按重量（元/吨）', value: 'by_weight' },
  { label: '按体积（元/m³）', value: 'by_volume' },
  { label: '按趟（元/趟）', value: 'by_trip' },
]

const cargoCategoryMap = computed(() => {
  const map = {}
  for (const item of cargoCategories.value) {
    map[item.id] = item.name
  }
  return map
})

const selectedOrderIds = computed(() => selectedOrders.value.map((item) => item.id))
const splitWeightTotal = computed(() => splitParts.value.reduce((sum, part) => sum + Number(part.cargo_weight_kg || 0), 0))
const splitVolumeTotal = computed(() => splitParts.value.reduce((sum, part) => sum + Number(part.cargo_volume_m3 || 0), 0))
const detailHistory = computed(() => {
  const list = detailOrder.value?.meta?.history
  return Array.isArray(list) ? [...list].reverse() : []
})

const formatDateTime = (value) => {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  const yyyy = date.getFullYear()
  const mm = String(date.getMonth() + 1).padStart(2, '0')
  const dd = String(date.getDate()).padStart(2, '0')
  const hh = String(date.getHours()).padStart(2, '0')
  const mi = String(date.getMinutes()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd} ${hh}:${mi}`
}

const resetCreateForm = () => {
  createForm.cargo_category_id = null
  createForm.client_name = ''
  createForm.pickup_site_id = null
  createForm.pickup_address = ''
  createForm.pickup_contact_name = ''
  createForm.pickup_contact_phone = ''
  createForm.dropoff_site_id = null
  createForm.dropoff_address = ''
  createForm.dropoff_contact_name = ''
  createForm.dropoff_contact_phone = ''
  createForm.cargo_weight_kg = null
  createForm.cargo_volume_m3 = null
  createForm.freight_calc_scheme = ''
  createForm.freight_unit_price = null
  createForm.freight_trip_count = 1
  createForm.actual_delivered_weight_kg = null
  createForm.loss_allowance_kg = 0
  createForm.loss_deduct_unit_price = null
  createForm.expected_pickup_at = ''
  createForm.expected_delivery_at = ''
  createFormRef.value?.clearValidate()
}

const resetEditForm = () => {
  editForm.cargo_category_id = null
  editForm.client_name = ''
  editForm.pickup_site_id = null
  editForm.pickup_address = ''
  editForm.pickup_contact_name = ''
  editForm.pickup_contact_phone = ''
  editForm.dropoff_site_id = null
  editForm.dropoff_address = ''
  editForm.dropoff_contact_name = ''
  editForm.dropoff_contact_phone = ''
  editForm.cargo_weight_kg = null
  editForm.cargo_volume_m3 = null
  editForm.freight_calc_scheme = ''
  editForm.freight_unit_price = null
  editForm.freight_trip_count = 1
  editForm.actual_delivered_weight_kg = null
  editForm.loss_allowance_kg = 0
  editForm.loss_deduct_unit_price = null
  editForm.expected_pickup_at = ''
  editForm.expected_delivery_at = ''
  editForm.status = ''
  currentEditId.value = null
  editFormRef.value?.clearValidate()
}

const resetManualDispatchForm = () => {
  manualDispatchForm.vehicle_id = null
  manualDispatchForm.estimated_distance_km = null
  manualDispatchForm.estimated_fuel_l = null
  manualDispatchForm.estimated_duration_min = null
}

const fillOrderForm = (target, row) => {
  target.cargo_category_id = row.cargo_category_id
  target.client_name = row.client_name || ''
  target.pickup_site_id = row.pickup_site_id || null
  target.pickup_address = row.pickup_address || ''
  target.pickup_contact_name = row.pickup_contact_name || ''
  target.pickup_contact_phone = row.pickup_contact_phone || ''
  target.dropoff_site_id = row.dropoff_site_id || null
  target.dropoff_address = row.dropoff_address || ''
  target.dropoff_contact_name = row.dropoff_contact_name || ''
  target.dropoff_contact_phone = row.dropoff_contact_phone || ''
  target.cargo_weight_kg = row.cargo_weight_kg
  target.cargo_volume_m3 = row.cargo_volume_m3
  target.freight_calc_scheme = row.freight_calc_scheme || ''
  target.freight_unit_price = row.freight_unit_price
  target.freight_trip_count = row.freight_trip_count || 1
  target.actual_delivered_weight_kg = row.actual_delivered_weight_kg
  target.loss_allowance_kg = row.loss_allowance_kg ?? 0
  target.loss_deduct_unit_price = row.loss_deduct_unit_price
  target.expected_pickup_at = row.expected_pickup_at ? formatDateTime(row.expected_pickup_at).replace(' ', 'T') : ''
  target.expected_delivery_at = row.expected_delivery_at ? formatDateTime(row.expected_delivery_at).replace(' ', 'T') : ''
}

const normalizeDate = (value) => {
  if (!value) return null
  if (typeof value === 'string' && value.includes('T')) {
    return value.replace('T', ' ')
  }
  return value
}

const buildOrderPayload = (form) => ({
  cargo_category_id: Number(form.cargo_category_id),
  client_name: form.client_name,
  pickup_site_id: form.pickup_site_id || null,
  pickup_address: form.pickup_address,
  pickup_contact_name: form.pickup_contact_name || null,
  pickup_contact_phone: form.pickup_contact_phone || null,
  dropoff_site_id: form.dropoff_site_id || null,
  dropoff_address: form.dropoff_address,
  dropoff_contact_name: form.dropoff_contact_name || null,
  dropoff_contact_phone: form.dropoff_contact_phone || null,
  cargo_weight_kg: form.cargo_weight_kg,
  cargo_volume_m3: form.cargo_volume_m3,
  freight_calc_scheme: form.freight_calc_scheme || null,
  freight_unit_price: form.freight_calc_scheme ? form.freight_unit_price : null,
  freight_trip_count: form.freight_calc_scheme === 'by_trip' ? form.freight_trip_count : null,
  actual_delivered_weight_kg: form.actual_delivered_weight_kg,
  loss_allowance_kg: form.loss_allowance_kg ?? 0,
  loss_deduct_unit_price: form.loss_deduct_unit_price,
  expected_pickup_at: normalizeDate(form.expected_pickup_at),
  expected_delivery_at: normalizeDate(form.expected_delivery_at),
})

const syncAddressBySite = (form, field) => {
  const siteIdKey = `${field}_site_id`
  const addressKey = `${field}_address`
  const site = sites.value.find((item) => Number(item.id) === Number(form[siteIdKey]))
  if (site?.address) {
    form[addressKey] = site.address
  }
}

const loadMeta = async () => {
  loadingMeta.value = true
  try {
    const { data } = await api.get('/meta')
    cargoCategories.value = Array.isArray(data?.cargo_categories) ? data.cargo_categories : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取基础字典失败')
  } finally {
    loadingMeta.value = false
  }
}

const loadSites = async () => {
  loadingSites.value = true
  try {
    const { data } = await api.post('/resource/site/list', { status: 'active' })
    sites.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取站点列表失败')
  } finally {
    loadingSites.value = false
  }
}

const loadVehicles = async () => {
  loadingVehicles.value = true
  try {
    const { data } = await api.post('/resource/vehicle/list', { status: 'idle' })
    vehicles.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取车辆列表失败')
  } finally {
    loadingVehicles.value = false
  }
}

const loadPrePlanOrders = async () => {
  loadingOrders.value = true
  try {
    const payload = {}
    if (filterForm.keyword.trim()) payload.keyword = filterForm.keyword.trim()
    if (filterForm.status) payload.status = filterForm.status
    if (filterForm.audit_status) payload.audit_status = filterForm.audit_status
    if (filterForm.is_locked !== '') payload.is_locked = filterForm.is_locked
    if (filterForm.cargo_category_id) payload.cargo_category_id = Number(filterForm.cargo_category_id)
    if (filterForm.trace_type) payload.trace_type = filterForm.trace_type

    const { data } = await api.post('/pre-plan-order/list', payload)
    prePlanOrders.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取预计划单失败')
  } finally {
    loadingOrders.value = false
  }
}

const resetFilters = async () => {
  filterForm.keyword = ''
  filterForm.status = ''
  filterForm.audit_status = ''
  filterForm.is_locked = ''
  filterForm.cargo_category_id = ''
  filterForm.trace_type = ''
  await loadPrePlanOrders()
}

const openCreateDialog = () => {
  resetCreateForm()
  createDialogVisible.value = true
}

const openBatchCreateDialog = () => {
  batchCreatePayloadText.value = ''
  batchCreateDialogVisible.value = true
}

const openImportDialog = () => {
  importFile.value = null
  importResult.value = null
  importDialogVisible.value = true
}

const downloadImportTemplate = async () => {
  const headers = [
    '货品分类编码',
    '客户名称',
    '装货地',
    '装货联系人',
    '装货联系电话',
    '卸货地',
    '收货联系人',
    '收货联系电话',
    '重量kg',
    '体积m3',
    '运价方式',
    '运价单价',
    '趟数',
    '实送重量kg',
    '允许亏吨kg',
    '亏吨扣减单价',
    '预计提货时间',
    '预计送达时间',
  ]

  const exampleRow = [
    'gasoline',
    '示例客户A',
    '上海油库A',
    '张三',
    '13900000001',
    '上海加油站B',
    '李四',
    '13900000002',
    30000,
    38.5,
    'by_weight',
    8.5,
    '',
    '',
    200,
    1.2,
    '2026-03-30 08:30:00',
    '2026-03-30 12:00:00',
  ]

  const tips = [
    ['填写说明'],
    ['1. 必填字段：货品分类编码/ID/名称（至少一个）、客户名称、装货地、卸货地'],
    ['2. 运价方式仅支持：by_weight / by_volume / by_trip'],
    ['3. 日期格式建议：YYYY-MM-DD HH:mm:ss'],
    ['4. 一次最多导入 200 条，请分批导入'],
  ]

  await exportAoaSheetsToXlsx({
    filename: '预计划单导入模板.xlsx',
    sheets: [
      {
        name: '计划单导入模板',
        rows: [headers, exampleRow],
      },
      {
        name: '填写说明',
        rows: tips,
        merges: [
          { startRow: 1, startCol: 1, endRow: 1, endCol: 8 },
          { startRow: 2, startCol: 1, endRow: 2, endCol: 8 },
          { startRow: 3, startCol: 1, endRow: 3, endCol: 8 },
          { startRow: 4, startCol: 1, endRow: 4, endCol: 8 },
          { startRow: 5, startCol: 1, endRow: 5, endCol: 8 },
        ],
      },
    ],
  })
}

const openEditDialog = (row) => {
  resetEditForm()
  currentEditId.value = row.id
  fillOrderForm(editForm, row)
  editForm.status = row.status
  editDialogVisible.value = true
}

const openSplitDialog = (row) => {
  if (row.status !== 'pending' || row.is_locked || row.status === 'cancelled') {
    ElMessage.warning('仅待调度且未锁定、未作废的计划单可拆单')
    return
  }
  splitTargetOrder.value = row
  splitPartCount.value = 2
  fillSplitPartsByRatio()
  splitDialogVisible.value = true
}

const openDetailDialog = async (row) => {
  detailLoading.value = true
  detailDialogVisible.value = true
  try {
    const { data } = await api.post('/pre-plan-order/detail', { id: row.id })
    detailOrder.value = data || null
  } catch (error) {
    detailOrder.value = null
    ElMessage.error(error?.response?.data?.message || '获取计划单详情失败')
    detailDialogVisible.value = false
  } finally {
    detailLoading.value = false
  }
}

const closeSplitDialog = () => {
  splitDialogVisible.value = false
  splitTargetOrder.value = null
  splitParts.value = []
}

const fillSplitPartsByRatio = () => {
  const row = splitTargetOrder.value
  if (!row) return
  const count = Number(splitPartCount.value || 0)
  if (!Number.isInteger(count) || count < 2 || count > 20) return
  const weight = Number(row.cargo_weight_kg || 0)
  const volume = Number(row.cargo_volume_m3 || 0)
  const next = []
  let remainingWeight = weight
  let remainingVolume = volume
  for (let i = 0; i < count; i++) {
    if (i === count - 1) {
      next.push({
        seq: i + 1,
        cargo_weight_kg: Number(remainingWeight.toFixed(2)),
        cargo_volume_m3: Number(remainingVolume.toFixed(2)),
      })
      break
    }
    const partWeight = Number((weight / count).toFixed(2))
    const partVolume = Number((volume / count).toFixed(2))
    remainingWeight -= partWeight
    remainingVolume -= partVolume
    next.push({
      seq: i + 1,
      cargo_weight_kg: partWeight,
      cargo_volume_m3: partVolume,
    })
  }
  splitParts.value = next
}

const parseBatchPayload = () => {
  const lines = String(batchCreatePayloadText.value || '')
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)

  return lines.map((line, index) => {
    try {
      return JSON.parse(line)
    } catch {
      throw new Error(`第 ${index + 1} 行 JSON 格式错误`)
    }
  })
}

const createPrePlanOrder = async () => {
  if (!createFormRef.value) return
  const valid = await createFormRef.value.validate().catch(() => false)
  if (!valid) return

  creating.value = true
  try {
    await api.post('/pre-plan-order/create', buildOrderPayload(createForm))
    ElMessage.success('预计划单创建成功')
    createDialogVisible.value = false
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '创建预计划单失败')
  } finally {
    creating.value = false
  }
}

const updatePrePlanOrder = async () => {
  if (!editFormRef.value || !currentEditId.value) return
  const valid = await editFormRef.value.validate().catch(() => false)
  if (!valid) return

  editing.value = true
  try {
    await api.post('/pre-plan-order/update', {
      id: currentEditId.value,
      ...buildOrderPayload(editForm),
    })
    ElMessage.success('预计划单修改成功')
    editDialogVisible.value = false
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '修改失败')
  } finally {
    editing.value = false
  }
}

const batchCreatePrePlanOrders = async () => {
  let orders = []
  try {
    orders = parseBatchPayload()
  } catch (error) {
    ElMessage.error(error?.message || '批量内容格式错误')
    return
  }

  if (!orders.length) {
    ElMessage.warning('请至少输入一条订单 JSON')
    return
  }

  creating.value = true
  try {
    await api.post('/pre-plan-order/batch-create', { orders })
    ElMessage.success(`批量创建成功，共 ${orders.length} 条`)
    batchCreateDialogVisible.value = false
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '批量创建失败')
  } finally {
    creating.value = false
  }
}

const handleImportFileChange = (file) => {
  importFile.value = file?.raw || null
}

const normalizeImportHeader = (header) => {
  const value = String(header || '').trim().toLowerCase()
  const map = {
    cargo_category_id: 'cargo_category_id',
    '货品分类id': 'cargo_category_id',
    '货品id': 'cargo_category_id',
    cargo_category_code: 'cargo_category_code',
    cargo_code: 'cargo_category_code',
    '货品分类编码': 'cargo_category_code',
    '分类编码': 'cargo_category_code',
    cargo_category_name: 'cargo_category_name',
    '货品分类名称': 'cargo_category_name',
    '货品分类': 'cargo_category_name',
    '分类名称': 'cargo_category_name',
    client_name: 'client_name',
    '客户名称': 'client_name',
    客户: 'client_name',
    pickup_address: 'pickup_address',
    '装货地': 'pickup_address',
    '装货地址': 'pickup_address',
    pickup_contact_name: 'pickup_contact_name',
    '装货联系人': 'pickup_contact_name',
    pickup_contact_phone: 'pickup_contact_phone',
    '装货联系电话': 'pickup_contact_phone',
    dropoff_address: 'dropoff_address',
    '卸货地': 'dropoff_address',
    '卸货地址': 'dropoff_address',
    '收货地址': 'dropoff_address',
    dropoff_contact_name: 'dropoff_contact_name',
    '收货联系人': 'dropoff_contact_name',
    '卸货联系人': 'dropoff_contact_name',
    dropoff_contact_phone: 'dropoff_contact_phone',
    '收货联系电话': 'dropoff_contact_phone',
    '卸货联系电话': 'dropoff_contact_phone',
    cargo_weight_kg: 'cargo_weight_kg',
    '重量kg': 'cargo_weight_kg',
    重量: 'cargo_weight_kg',
    cargo_volume_m3: 'cargo_volume_m3',
    '体积m3': 'cargo_volume_m3',
    体积: 'cargo_volume_m3',
    freight_calc_scheme: 'freight_calc_scheme',
    '运费计算方式': 'freight_calc_scheme',
    '运价方式': 'freight_calc_scheme',
    freight_unit_price: 'freight_unit_price',
    '运费单价': 'freight_unit_price',
    '运价单价': 'freight_unit_price',
    freight_trip_count: 'freight_trip_count',
    趟数: 'freight_trip_count',
    actual_delivered_weight_kg: 'actual_delivered_weight_kg',
    '实送重量kg': 'actual_delivered_weight_kg',
    '实送重量': 'actual_delivered_weight_kg',
    loss_allowance_kg: 'loss_allowance_kg',
    '允许亏吨kg': 'loss_allowance_kg',
    '允许亏吨': 'loss_allowance_kg',
    loss_deduct_unit_price: 'loss_deduct_unit_price',
    '亏吨扣减单价': 'loss_deduct_unit_price',
    '亏吨单价': 'loss_deduct_unit_price',
    expected_pickup_at: 'expected_pickup_at',
    '预计提货时间': 'expected_pickup_at',
    expected_delivery_at: 'expected_delivery_at',
    '预计送达时间': 'expected_delivery_at',
  }
  return map[value] || ''
}

const resolveCargoCategoryId = (row) => {
  if (row.cargo_category_id !== undefined && row.cargo_category_id !== '') {
    const id = Number(row.cargo_category_id)
    if (!Number.isNaN(id) && cargoCategories.value.some((item) => Number(item.id) === id)) {
      return id
    }
  }
  if (row.cargo_category_code) {
    const matched = cargoCategories.value.find((item) => String(item.code) === String(row.cargo_category_code))
    if (matched) return Number(matched.id)
  }
  if (row.cargo_category_name) {
    const matched = cargoCategories.value.find((item) => String(item.name) === String(row.cargo_category_name))
    if (matched) return Number(matched.id)
  }
  return null
}

const parseImportFile = async (file) => {
  const rawRows = await parseSpreadsheetFile(file)

  return rawRows.map((item, index) => {
    const normalized = {}
    for (const [key, value] of Object.entries(item || {})) {
      const mappedKey = normalizeImportHeader(key)
      if (!mappedKey) continue
      normalized[mappedKey] = String(value ?? '').trim()
    }
    return {
      line: index + 2,
      row: normalized,
    }
  }).filter((item) => Object.keys(item.row).length > 0)
}

const submitImportCsv = async () => {
  if (!importFile.value) {
    ElMessage.warning('请先选择导入文件')
    return
  }

  importing.value = true
  try {
    const parsedRows = await parseImportFile(importFile.value)
    const validOrders = []
    const errors = []

    for (const item of parsedRows) {
      const row = item.row
      const cargoCategoryId = resolveCargoCategoryId(row)
      const clientName = String(row.client_name || '').trim()
      const pickupAddress = String(row.pickup_address || '').trim()
      const dropoffAddress = String(row.dropoff_address || '').trim()

      if (!cargoCategoryId) {
        errors.push({ line: item.line, message: '无法匹配货品分类（请填写分类ID/编码/名称）' })
        continue
      }
      if (!clientName || !pickupAddress || !dropoffAddress) {
        errors.push({ line: item.line, message: 'client_name、pickup_address、dropoff_address 为必填' })
        continue
      }

      validOrders.push({
        cargo_category_id: cargoCategoryId,
        client_name: clientName,
        pickup_address: pickupAddress,
        pickup_contact_name: row.pickup_contact_name || null,
        pickup_contact_phone: row.pickup_contact_phone || null,
        dropoff_address: dropoffAddress,
        dropoff_contact_name: row.dropoff_contact_name || null,
        dropoff_contact_phone: row.dropoff_contact_phone || null,
        cargo_weight_kg: row.cargo_weight_kg !== '' ? Number(row.cargo_weight_kg) : null,
        cargo_volume_m3: row.cargo_volume_m3 !== '' ? Number(row.cargo_volume_m3) : null,
        freight_calc_scheme: row.freight_calc_scheme || null,
        freight_unit_price: row.freight_unit_price !== '' ? Number(row.freight_unit_price) : null,
        freight_trip_count: row.freight_trip_count !== '' ? Number(row.freight_trip_count) : null,
        actual_delivered_weight_kg: row.actual_delivered_weight_kg !== '' ? Number(row.actual_delivered_weight_kg) : null,
        loss_allowance_kg: row.loss_allowance_kg !== '' ? Number(row.loss_allowance_kg) : null,
        loss_deduct_unit_price: row.loss_deduct_unit_price !== '' ? Number(row.loss_deduct_unit_price) : null,
        expected_pickup_at: row.expected_pickup_at || null,
        expected_delivery_at: row.expected_delivery_at || null,
      })
    }

    if (validOrders.length > 200) {
      ElMessage.warning('单次最多导入 200 条，请分批上传')
      importing.value = false
      return
    }

    let createdCount = 0
    if (validOrders.length > 0) {
      const { data } = await api.post('/pre-plan-order/batch-create', {
        orders: validOrders,
      })
      createdCount = Number(data?.count || 0)
    }

    importResult.value = {
      created_count: createdCount,
      failed_count: errors.length,
      errors,
    }
    ElMessage.success(`导入完成：成功 ${createdCount} 条，失败 ${errors.length} 条`)
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '导入失败')
  } finally {
    importing.value = false
  }
}

const submitSplitOrder = async () => {
  const row = splitTargetOrder.value
  if (!row?.id) return
  const count = Number(splitPartCount.value || 0)
  if (!Number.isInteger(count) || count < 2 || count > 20) {
    ElMessage.warning('拆分份数需在 2-20 之间')
    return
  }
  if (splitParts.value.length !== count) {
    ElMessage.warning('拆分明细与拆分份数不一致，请先点击“按比例填充”')
    return
  }

  const weight = Number(row.cargo_weight_kg || 0)
  const volume = Number(row.cargo_volume_m3 || 0)
  if (Math.abs(splitWeightTotal.value - weight) > 0.01) {
    ElMessage.warning('拆分重量合计需等于原单重量')
    return
  }
  if (Math.abs(splitVolumeTotal.value - volume) > 0.01) {
    ElMessage.warning('拆分体积合计需等于原单体积')
    return
  }

  const parts = splitParts.value.map((item) => ({
    cargo_weight_kg: Number(item.cargo_weight_kg || 0),
    cargo_volume_m3: Number(item.cargo_volume_m3 || 0),
  }))

  splitting.value = true
  try {
    await api.post('/pre-plan-order/split', {
      id: row.id,
      parts,
    })
    ElMessage.success('拆单成功')
    closeSplitDialog()
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '拆单失败')
  } finally {
    splitting.value = false
  }
}

const getMergeBaseIssues = (row) => {
  const reasons = []
  if (row.status !== 'pending') reasons.push('状态非待调度')
  if (row.is_locked) reasons.push('已锁单')
  if (row.status === 'cancelled') reasons.push('已作废')
  return reasons
}

const getMergeDiffLabels = (base, row) =>
  mergeCompareKeys
    .filter((item) => String(row?.[item.key] ?? '') !== String(base?.[item.key] ?? ''))
    .map((item) => item.label)

const getTraceSummary = (row) => {
  const meta = row?.meta || {}
  if (meta.split_from_id) {
    return `拆分自 #${meta.split_from_id}${meta.split_part_no ? `（第${meta.split_part_no}份）` : ''}`
  }
  if (Array.isArray(meta.merge_from_ids) && meta.merge_from_ids.length) {
    return `并自 ${meta.merge_from_ids.length} 单`
  }
  return '-'
}

const openTraceDetail = async (row) => {
  const meta = row?.meta || {}
  const lines = [`当前单号：${row.order_no || '-'}`, `追溯信息：${getTraceSummary(row)}`]
  if (meta.split_from_id) {
    lines.push(`来源单ID：${meta.split_from_id}`)
    if (meta.split_part_no) lines.push(`拆分分片：第 ${meta.split_part_no} 份`)
  }
  if (Array.isArray(meta.merge_from_ids) && meta.merge_from_ids.length) {
    lines.push(`并单来源ID：${meta.merge_from_ids.join('、')}`)
  }
  await ElMessageBox.alert(lines.join('\n'), '拆并追溯详情', {
    confirmButtonText: '我知道了',
    type: 'info',
  })
}

const recommendMergeOrders = async () => {
  if (selectedOrders.value.length !== 1) {
    ElMessage.warning('请先只勾选 1 条基准单，再执行推荐可并单')
    return
  }

  const base = selectedOrders.value[0]
  const baseIssues = getMergeBaseIssues(base)
  if (baseIssues.length) {
    ElMessage.warning(`基准单不可并单：${base.order_no}（${baseIssues.join('、')}）`)
    return
  }

  const matched = []
  for (const row of prePlanOrders.value) {
    const issues = getMergeBaseIssues(row)
    const diff = row.id === base.id ? [] : getMergeDiffLabels(base, row)
    if (!issues.length && !diff.length) {
      matched.push(row)
    }
  }

  if (matched.length < 2) {
    ElMessage.info(`未找到可与 ${base.order_no} 并单的其他订单，请调整筛选条件后重试`)
    return
  }

  clearSelection()
  await nextTick()
  for (const row of matched) {
    tableRef.value?.toggleRowSelection?.(row, true)
  }
  ElMessage.success(`已自动勾选 ${matched.length} 条可并单订单（基准单：${base.order_no}）`)
}

const mergeSelectedOrders = async () => {
  if (selectedOrders.value.length < 2) {
    ElMessage.warning('请先勾选至少两条计划单再并单')
    return
  }

  const [base, ...rest] = selectedOrders.value
  const issues = []

  for (const row of selectedOrders.value) {
    const reasons = []
    if (row.status !== 'pending') reasons.push('状态非待调度')
    if (row.is_locked) reasons.push('已锁单')
    if (row.status === 'cancelled') reasons.push('已作废')
    if (reasons.length) {
      issues.push(`${row.order_no}: ${reasons.join('、')}`)
    }
  }

  for (const row of rest) {
    const diff = getMergeDiffLabels(base, row)
    if (diff.length) {
      issues.push(`${row.order_no}: 与基准单 ${base.order_no} 的${diff.join('、')}不一致`)
    }
  }

  if (issues.length) {
    await ElMessageBox.alert(issues.join('\n'), '并单预检查未通过', {
      confirmButtonText: '我知道了',
      type: 'warning',
    })
    return
  }

  const ids = selectedOrders.value.map((item) => item.id)
  managingOrder.value = true
  try {
    await api.post('/pre-plan-order/merge', { ids })
    ElMessage.success('并单成功')
    clearSelection()
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '并单失败')
  } finally {
    managingOrder.value = false
  }
}

const onSelectionChange = (rows) => {
  selectedOrders.value = rows
}

const selectableOrder = (row) => canDispatchOrder(row)

const canDispatchOrder = (row) => dispatchableStatusSet.has(row.status) && row.audit_status === 'approved'

const clearSelection = () => {
  tableRef.value?.clearSelection?.()
  selectedOrders.value = []
}

const openManualDispatchDialog = async () => {
  if (selectedOrders.value.length === 0) {
    ElMessage.warning('请先勾选至少一条预计划单')
    return
  }

  const invalid = selectedOrders.value.find((item) => !canDispatchOrder(item))
  if (invalid) {
    ElMessage.warning(`存在不可派单订单（状态或审核不满足）：${invalid.order_no}`)
    return
  }

  resetManualDispatchForm()
  manualDispatchDialogVisible.value = true
  await loadVehicles()
}

const approveOrder = async (row) => {
  auditing.value = true
  try {
    await api.post('/pre-plan-order/audit-approve', { id: row.id })
    ElMessage.success('审核通过')
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '审核通过失败')
  } finally {
    auditing.value = false
  }
}

const rejectOrder = async (row) => {
  const { value } = await ElMessageBox.prompt('请输入驳回原因', '驳回计划单', {
    confirmButtonText: '确认驳回',
    cancelButtonText: '取消',
    inputPlaceholder: '驳回原因（必填）',
    inputValidator: (v) => (String(v || '').trim().length > 0 ? true : '请输入驳回原因'),
  }).catch(() => ({ value: null }))

  if (!value) return

  auditing.value = true
  try {
    await api.post('/pre-plan-order/audit-reject', {
      id: row.id,
      audit_remark: String(value).trim(),
    })
    ElMessage.success('已驳回')
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '驳回失败')
  } finally {
    auditing.value = false
  }
}

const lockOrder = async (row) => {
  managingOrder.value = true
  try {
    await api.post('/pre-plan-order/lock', { id: row.id })
    ElMessage.success('已锁单')
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '锁单失败')
  } finally {
    managingOrder.value = false
  }
}

const unlockOrder = async (row) => {
  managingOrder.value = true
  try {
    await api.post('/pre-plan-order/unlock', { id: row.id })
    ElMessage.success('已解锁')
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '解锁失败')
  } finally {
    managingOrder.value = false
  }
}

const voidOrder = async (row) => {
  const { value } = await ElMessageBox.prompt('请输入作废原因', '作废计划单', {
    confirmButtonText: '确认作废',
    cancelButtonText: '取消',
    inputPlaceholder: '作废原因（必填）',
    inputValidator: (v) => (String(v || '').trim().length > 0 ? true : '请输入作废原因'),
  }).catch(() => ({ value: null }))

  if (!value) return

  managingOrder.value = true
  try {
    await api.post('/pre-plan-order/void', {
      id: row.id,
      void_remark: String(value).trim(),
    })
    ElMessage.success('计划单已作废')
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '作废失败')
  } finally {
    managingOrder.value = false
  }
}

const submitManualDispatch = async () => {
  if (!manualDispatchForm.vehicle_id) {
    ElMessage.warning('请选择派单车辆')
    return
  }
  if (selectedOrderIds.value.length === 0) {
    ElMessage.warning('没有可派单的订单')
    return
  }

  dispatching.value = true
  try {
    await api.post('/dispatch/manual-create-tasks', {
      assignments: [
        {
          vehicle_id: manualDispatchForm.vehicle_id,
          order_ids: selectedOrderIds.value,
          estimated_distance_km: manualDispatchForm.estimated_distance_km,
          estimated_fuel_l: manualDispatchForm.estimated_fuel_l,
          estimated_duration_min: manualDispatchForm.estimated_duration_min,
          route_meta: {
            strategy: 'pre_plan_manual_dispatch',
            source: 'pre_plan_order',
          },
        },
      ],
    })

    ElMessage.success('手动派单成功')
    manualDispatchDialogVisible.value = false
    clearSelection()
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '手动派单失败')
  } finally {
    dispatching.value = false
  }
}

onMounted(() => {
  loadPrePlanOrders()
  loadMeta()
  loadSites()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <div class="card-title">预计划单管理</div>
        <div>
          <el-button class="mr-8" plain type="info" @click="recommendMergeOrders">推荐可并单</el-button>
          <el-button class="mr-8" plain type="warning" @click="mergeSelectedOrders">并单(选中)</el-button>
          <el-button class="mr-8" plain type="primary" @click="openManualDispatchDialog">手动派单</el-button>
          <el-button class="mr-8" plain @click="downloadImportTemplate">下载模板</el-button>
          <el-button class="mr-8" plain @click="openImportDialog">导入文件</el-button>
          <el-button class="mr-8" plain @click="openBatchCreateDialog">批量创建</el-button>
          <el-button type="primary" @click="openCreateDialog">新建预计划单</el-button>
        </div>
      </div>
    </template>
    <el-form inline class="mb-12">
      <el-form-item label="关键词">
        <el-input v-model="filterForm.keyword" clearable placeholder="订单号/客户/装卸地址/联系人" style="width: 240px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="filterForm.status" clearable placeholder="全部状态" style="width: 140px">
          <el-option label="待调度" value="pending" />
          <el-option label="已排程" value="scheduled" />
          <el-option label="执行中" value="in_progress" />
          <el-option label="已完成" value="completed" />
          <el-option label="已作废" value="cancelled" />
        </el-select>
      </el-form-item>
      <el-form-item label="审核">
        <el-select v-model="filterForm.audit_status" clearable placeholder="全部审核" style="width: 140px">
          <el-option label="待审核" value="pending_approval" />
          <el-option label="已审核" value="approved" />
          <el-option label="已驳回" value="rejected" />
        </el-select>
      </el-form-item>
      <el-form-item label="锁单">
        <el-select v-model="filterForm.is_locked" clearable placeholder="全部" style="width: 120px">
          <el-option label="已锁定" :value="true" />
          <el-option label="未锁定" :value="false" />
        </el-select>
      </el-form-item>
      <el-form-item label="货品分类">
        <el-select v-model="filterForm.cargo_category_id" clearable placeholder="全部分类" style="width: 180px">
          <el-option
            v-for="item in cargoCategories"
            :key="`filter-cargo-${item.id}`"
            :label="`${item.name}（${item.code}）`"
            :value="item.id"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="追溯类型">
        <el-select v-model="filterForm.trace_type" clearable placeholder="全部类型" style="width: 140px">
          <el-option label="普通单" value="origin" />
          <el-option label="拆分单" value="split" />
          <el-option label="并单" value="merge" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="loadPrePlanOrders">查询</el-button>
        <el-button @click="resetFilters">重置</el-button>
      </el-form-item>
    </el-form>
    <el-table ref="tableRef" :data="prePlanOrders" stripe v-loading="loadingOrders" @selection-change="onSelectionChange">
      <el-table-column type="selection" width="50" :selectable="selectableOrder" />
      <el-table-column prop="order_no" label="预计划单号" min-width="180" />
      <el-table-column prop="client_name" label="客户" min-width="120" />
      <el-table-column prop="pickup_address" label="装货地" min-width="180" />
      <el-table-column label="装货联系人" min-width="150">
        <template #default="{ row }">
          {{ row.pickup_contact_name || '-' }} / {{ row.pickup_contact_phone || '-' }}
        </template>
      </el-table-column>
      <el-table-column prop="dropoff_address" label="卸货地" min-width="180" />
      <el-table-column label="收货联系人" min-width="150">
        <template #default="{ row }">
          {{ row.dropoff_contact_name || '-' }} / {{ row.dropoff_contact_phone || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="货品分类" min-width="130">
        <template #default="{ row }">
          {{ cargoCategoryMap[row.cargo_category_id] || `分类#${row.cargo_category_id}` }}
        </template>
      </el-table-column>
      <el-table-column prop="cargo_weight_kg" label="重量(kg)" min-width="100" />
      <el-table-column prop="cargo_volume_m3" label="体积(m³)" min-width="100" />
      <el-table-column label="运费方案" min-width="130">
        <template #default="{ row }">
          {{ getLabel(freightSchemeLabelMap, row.freight_calc_scheme) }}
        </template>
      </el-table-column>
      <el-table-column label="已算运费(元)" min-width="120">
        <template #default="{ row }">
          <div>{{ row.freight_amount ?? '-' }}</div>
          <div class="text-secondary" v-if="row.freight_base_amount !== null || row.freight_loss_deduct_amount !== null">
            基础:{{ row.freight_base_amount ?? 0 }} / 扣减:{{ row.freight_loss_deduct_amount ?? 0 }}
          </div>
        </template>
      </el-table-column>
      <el-table-column label="预计提货" min-width="160">
        <template #default="{ row }">
          {{ formatDateTime(row.expected_pickup_at) }}
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="100">
        <template #default="{ row }">
          <el-tag :type="statusTypeMap[row.status] || 'info'">
            {{ getLabel(taskStatusLabelMap, row.status) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="审核" min-width="130">
        <template #default="{ row }">
          <el-tag :type="auditStatusTypeMap[row.audit_status] || 'info'">
            {{ getLabel(auditStatusLabelMap, row.audit_status) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="锁单" min-width="80">
        <template #default="{ row }">
          <el-tag :type="row.is_locked ? 'warning' : 'info'">{{ row.is_locked ? '已锁定' : '未锁定' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="audit_remark" label="审核备注" min-width="160" />
      <el-table-column label="作废原因" min-width="160">
        <template #default="{ row }">
          {{ row.void_remark || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="拆并追溯" min-width="160">
        <template #default="{ row }">
          {{ getTraceSummary(row) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" width="340" fixed="right">
        <template #default="{ row }">
          <el-button
            link
            type="primary"
            @click="openDetailDialog(row)"
          >
            详情
          </el-button>
          <el-button
            link
            type="primary"
            :disabled="!editableStatusSet.has(row.status) || row.is_locked || row.status === 'cancelled'"
            @click="openEditDialog(row)"
          >
            编辑
          </el-button>
          <el-button
            v-if="!row.is_locked && row.status !== 'cancelled'"
            link
            type="warning"
            :loading="managingOrder"
            @click="lockOrder(row)"
          >
            锁单
          </el-button>
          <el-button
            v-if="row.is_locked && row.status !== 'cancelled'"
            link
            type="success"
            :loading="managingOrder"
            @click="unlockOrder(row)"
          >
            解锁
          </el-button>
          <el-button
            v-if="row.status !== 'cancelled'"
            link
            type="danger"
            :loading="managingOrder"
            @click="voidOrder(row)"
          >
            作废
          </el-button>
          <el-button
            v-if="row.status !== 'cancelled'"
            link
            type="primary"
            :loading="splitting"
            @click="openSplitDialog(row)"
          >
            拆单
          </el-button>
          <el-button
            link
            type="info"
            @click="openTraceDetail(row)"
          >
            追溯
          </el-button>
          <el-button
            v-if="row.audit_status === 'pending_approval'"
            link
            type="success"
            :loading="auditing"
            @click="approveOrder(row)"
          >
            通过
          </el-button>
          <el-button
            v-if="row.audit_status === 'pending_approval'"
            link
            type="danger"
            :loading="auditing"
            @click="rejectOrder(row)"
          >
            驳回
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog v-model="splitDialogVisible" title="拆分计划单" width="460px" destroy-on-close>
    <el-form label-width="100px">
      <el-form-item label="原计划单">
        <span>{{ splitTargetOrder?.order_no || '-' }}</span>
      </el-form-item>
      <el-form-item label="拆分份数">
        <el-input-number v-model="splitPartCount" :min="2" :max="20" :precision="0" />
      </el-form-item>
      <el-form-item>
        <el-button size="small" @click="fillSplitPartsByRatio">按比例填充</el-button>
      </el-form-item>
      <el-table :data="splitParts" size="small" stripe>
        <el-table-column prop="seq" label="#" width="50" />
        <el-table-column label="重量(kg)" min-width="120">
          <template #default="{ row }">
            <el-input-number v-model="row.cargo_weight_kg" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </template>
        </el-table-column>
        <el-table-column label="体积(m³)" min-width="120">
          <template #default="{ row }">
            <el-input-number v-model="row.cargo_volume_m3" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </template>
        </el-table-column>
      </el-table>
      <div class="mt-12 text-secondary">
        重量合计：{{ splitWeightTotal.toFixed(2) }} / 原重量：{{ Number(splitTargetOrder?.cargo_weight_kg || 0).toFixed(2) }}
      </div>
      <div class="text-secondary">
        体积合计：{{ splitVolumeTotal.toFixed(2) }} / 原体积：{{ Number(splitTargetOrder?.cargo_volume_m3 || 0).toFixed(2) }}
      </div>
      <el-alert class="mt-12" type="info" :closable="false" show-icon title="拆单成功后原单自动作废。" />
    </el-form>
    <template #footer>
      <el-button @click="closeSplitDialog">取消</el-button>
      <el-button type="primary" :loading="splitting" @click="submitSplitOrder">确认拆单</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="detailDialogVisible" title="预计划单详情" width="860px" destroy-on-close>
    <el-skeleton :loading="detailLoading" animated :rows="6">
      <template #default>
        <el-descriptions v-if="detailOrder" :column="2" border size="small">
          <el-descriptions-item label="预计划单号">{{ detailOrder.order_no || '-' }}</el-descriptions-item>
          <el-descriptions-item label="客户">{{ detailOrder.client_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="装货地">{{ detailOrder.pickup_address || '-' }}</el-descriptions-item>
          <el-descriptions-item label="卸货地">{{ detailOrder.dropoff_address || '-' }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ getLabel(taskStatusLabelMap, detailOrder.status) }}</el-descriptions-item>
          <el-descriptions-item label="审核">{{ getLabel(auditStatusLabelMap, detailOrder.audit_status) }}</el-descriptions-item>
        </el-descriptions>
        <el-divider content-position="left">操作日志</el-divider>
        <el-table :data="detailHistory" size="small" stripe>
          <el-table-column label="时间" min-width="160">
            <template #default="{ row }">
              {{ formatDateTime(row.at) }}
            </template>
          </el-table-column>
          <el-table-column label="动作" min-width="140">
            <template #default="{ row }">
              {{ historyActionLabelMap[row.action] || row.action || '-' }}
            </template>
          </el-table-column>
          <el-table-column label="操作人" min-width="140">
            <template #default="{ row }">
              {{ row.operator_name || row.operator_account || (row.operator_id ? `#${row.operator_id}` : '-') }}
            </template>
          </el-table-column>
          <el-table-column label="附加信息" min-width="260">
            <template #default="{ row }">
              {{ row.extra && Object.keys(row.extra).length ? JSON.stringify(row.extra) : '-' }}
            </template>
          </el-table-column>
        </el-table>
      </template>
    </el-skeleton>
    <template #footer>
      <el-button @click="detailDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="importDialogVisible" title="导入预计划单（XLSX/CSV）" width="760px" destroy-on-close>
    <el-alert
      class="mb-12"
      type="info"
      :closable="false"
      show-icon
      title="表头支持中英文（推荐 XLSX）：货品分类、客户名称、装货地、卸货地（其余字段选填）"
    />
    <el-upload
      drag
      :auto-upload="false"
      :limit="1"
      accept=".xlsx,.xls,.csv,.txt"
      :on-change="handleImportFileChange"
      :show-file-list="true"
    >
      <el-icon class="el-icon--upload"><UploadFilled /></el-icon>
      <div class="el-upload__text">拖拽文件到此处或 <em>点击上传</em></div>
    </el-upload>

    <el-card v-if="importResult" class="mt-12" shadow="never">
      <div>成功：{{ importResult.created_count || 0 }} 条</div>
      <div>失败：{{ importResult.failed_count || 0 }} 条</div>
      <el-table
        v-if="Array.isArray(importResult.errors) && importResult.errors.length"
        :data="importResult.errors"
        size="small"
        class="mt-12"
      >
        <el-table-column prop="line" label="行号" width="90" />
        <el-table-column prop="message" label="错误信息" min-width="240" />
      </el-table>
    </el-card>

    <template #footer>
      <el-button @click="importDialogVisible = false">关闭</el-button>
      <el-button type="primary" :loading="importing" @click="submitImportCsv">开始导入</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="batchCreateDialogVisible" title="批量创建预计划单" width="760px" destroy-on-close>
    <el-alert
      class="mb-12"
      type="info"
      :closable="false"
      show-icon
      title="每行一个 JSON 对象，必填字段：cargo_category_id、client_name、pickup_address、dropoff_address"
    />
    <el-input
      v-model="batchCreatePayloadText"
      type="textarea"
      :rows="12"
      placeholder='{"cargo_category_id":1,"client_name":"客户A","pickup_address":"上海仓A","dropoff_address":"上海店A"}'
    />
    <template #footer>
      <el-button @click="batchCreateDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="creating" @click="batchCreatePrePlanOrders">确认批量创建</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="createDialogVisible" title="新建预计划单" width="680px" destroy-on-close>
    <el-form ref="createFormRef" :model="createForm" :rules="formRules" label-width="120px">
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="货品分类" prop="cargo_category_id">
            <el-select v-model="createForm.cargo_category_id" :loading="loadingMeta" placeholder="请选择货品分类" style="width: 100%">
              <el-option
                v-for="item in cargoCategories"
                :key="item.id"
                :label="`${item.name}（${item.code}）`"
                :value="item.id"
              />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="客户名称" prop="client_name">
            <el-input v-model="createForm.client_name" placeholder="请输入客户名称" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="装货站点">
        <el-select
          v-model="createForm.pickup_site_id"
          clearable
          filterable
          :loading="loadingSites"
          placeholder="优先选择装货站点"
          style="width: 100%"
          @change="syncAddressBySite(createForm, 'pickup')"
        >
          <el-option v-for="site in sites" :key="`pickup-${site.id}`" :label="`${site.name}｜${site.address}`" :value="site.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="装货地" prop="pickup_address">
        <el-select
          v-model="createForm.pickup_address"
          filterable
          allow-create
          default-first-option
          clearable
          :loading="loadingSites"
          placeholder="请选择或输入装货地地址"
          style="width: 100%"
        >
          <el-option v-for="site in sites" :key="site.id" :label="`${site.name}｜${site.address}`" :value="site.address" />
        </el-select>
      </el-form-item>
      <el-form-item label="卸货站点">
        <el-select
          v-model="createForm.dropoff_site_id"
          clearable
          filterable
          :loading="loadingSites"
          placeholder="优先选择卸货站点"
          style="width: 100%"
          @change="syncAddressBySite(createForm, 'dropoff')"
        >
          <el-option v-for="site in sites" :key="`dropoff-${site.id}`" :label="`${site.name}｜${site.address}`" :value="site.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="卸货地" prop="dropoff_address">
        <el-select
          v-model="createForm.dropoff_address"
          filterable
          allow-create
          default-first-option
          clearable
          :loading="loadingSites"
          placeholder="请选择或输入卸货地地址"
          style="width: 100%"
        >
          <el-option v-for="site in sites" :key="`dropoff-${site.id}`" :label="`${site.name}｜${site.address}`" :value="site.address" />
        </el-select>
      </el-form-item>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="装货联系人">
            <el-input v-model="createForm.pickup_contact_name" placeholder="请输入装货联系人" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="装货联系电话">
            <el-input v-model="createForm.pickup_contact_phone" placeholder="请输入装货联系电话" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="收货联系人">
            <el-input v-model="createForm.dropoff_contact_name" placeholder="请输入收货联系人" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="收货联系电话">
            <el-input v-model="createForm.dropoff_contact_phone" placeholder="请输入收货联系电话" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="重量(kg)">
            <el-input-number v-model="createForm.cargo_weight_kg" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="体积(m³)">
            <el-input-number v-model="createForm.cargo_volume_m3" :min="0" :precision="3" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="预计提货时间">
            <el-date-picker
              v-model="createForm.expected_pickup_at"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="请选择预计提货时间"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="预计送达时间">
            <el-date-picker
              v-model="createForm.expected_delivery_at"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="请选择预计送达时间"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
      </el-row>
      <el-divider content-position="left">运费计算方案</el-divider>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="计算方式">
            <el-select v-model="createForm.freight_calc_scheme" placeholder="请选择运费方案" style="width: 100%">
              <el-option
                v-for="item in freightSchemeOptions"
                :key="`create-freight-${item.value}`"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="单价">
            <el-input-number
              v-model="createForm.freight_unit_price"
              :min="0"
              :precision="2"
              :controls="false"
              style="width: 100%"
              placeholder="请输入运费单价"
            />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="趟数（按趟）" v-if="createForm.freight_calc_scheme === 'by_trip'">
            <el-input-number
              v-model="createForm.freight_trip_count"
              :min="1"
              :precision="0"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
      </el-row>
      <el-divider content-position="left">亏吨扣减配置（独立于运价方式）</el-divider>
      <el-row :gutter="12">
        <el-col :span="8">
          <el-form-item label="实送重量kg（完单前可空）">
            <el-input-number
              v-model="createForm.actual_delivered_weight_kg"
              :min="0"
              :precision="2"
              :controls="false"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="允许亏吨kg">
            <el-input-number
              v-model="createForm.loss_allowance_kg"
              :min="0"
              :precision="2"
              :controls="false"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="亏吨扣减单价(元/吨)">
            <el-input-number
              v-model="createForm.loss_deduct_unit_price"
              :min="0"
              :precision="2"
              :controls="false"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
      </el-row>
    </el-form>
    <template #footer>
      <el-button @click="createDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="creating" @click="createPrePlanOrder">创建</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="editDialogVisible" title="编辑预计划单" width="680px" destroy-on-close>
    <el-form ref="editFormRef" :model="editForm" :rules="formRules" label-width="120px">
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="货品分类" prop="cargo_category_id">
            <el-select v-model="editForm.cargo_category_id" :loading="loadingMeta" placeholder="请选择货品分类" style="width: 100%">
              <el-option
                v-for="item in cargoCategories"
                :key="`edit-${item.id}`"
                :label="`${item.name}（${item.code}）`"
                :value="item.id"
              />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="客户名称" prop="client_name">
            <el-input v-model="editForm.client_name" placeholder="请输入客户名称" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="装货站点">
        <el-select
          v-model="editForm.pickup_site_id"
          clearable
          filterable
          :loading="loadingSites"
          placeholder="优先选择装货站点"
          style="width: 100%"
          @change="syncAddressBySite(editForm, 'pickup')"
        >
          <el-option v-for="site in sites" :key="`edit-pickup-${site.id}`" :label="`${site.name}｜${site.address}`" :value="site.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="装货地" prop="pickup_address">
        <el-select
          v-model="editForm.pickup_address"
          filterable
          allow-create
          default-first-option
          clearable
          :loading="loadingSites"
          placeholder="请选择或输入装货地地址"
          style="width: 100%"
        >
          <el-option v-for="site in sites" :key="`edit-p-${site.id}`" :label="`${site.name}｜${site.address}`" :value="site.address" />
        </el-select>
      </el-form-item>
      <el-form-item label="卸货站点">
        <el-select
          v-model="editForm.dropoff_site_id"
          clearable
          filterable
          :loading="loadingSites"
          placeholder="优先选择卸货站点"
          style="width: 100%"
          @change="syncAddressBySite(editForm, 'dropoff')"
        >
          <el-option v-for="site in sites" :key="`edit-dropoff-${site.id}`" :label="`${site.name}｜${site.address}`" :value="site.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="卸货地" prop="dropoff_address">
        <el-select
          v-model="editForm.dropoff_address"
          filterable
          allow-create
          default-first-option
          clearable
          :loading="loadingSites"
          placeholder="请选择或输入卸货地地址"
          style="width: 100%"
        >
          <el-option v-for="site in sites" :key="`edit-d-${site.id}`" :label="`${site.name}｜${site.address}`" :value="site.address" />
        </el-select>
      </el-form-item>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="装货联系人">
            <el-input v-model="editForm.pickup_contact_name" placeholder="请输入装货联系人" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="装货联系电话">
            <el-input v-model="editForm.pickup_contact_phone" placeholder="请输入装货联系电话" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="收货联系人">
            <el-input v-model="editForm.dropoff_contact_name" placeholder="请输入收货联系人" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="收货联系电话">
            <el-input v-model="editForm.dropoff_contact_phone" placeholder="请输入收货联系电话" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="重量(kg)">
            <el-input-number v-model="editForm.cargo_weight_kg" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="体积(m³)">
            <el-input-number v-model="editForm.cargo_volume_m3" :min="0" :precision="3" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="预计提货时间">
            <el-date-picker
              v-model="editForm.expected_pickup_at"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="请选择预计提货时间"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="预计送达时间">
            <el-date-picker
              v-model="editForm.expected_delivery_at"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="请选择预计送达时间"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
      </el-row>
      <el-divider content-position="left">运费计算方案</el-divider>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="计算方式">
            <el-select v-model="editForm.freight_calc_scheme" placeholder="请选择运费方案" style="width: 100%">
              <el-option
                v-for="item in freightSchemeOptions"
                :key="`edit-freight-${item.value}`"
                :label="item.label"
                :value="item.value"
              />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="单价">
            <el-input-number
              v-model="editForm.freight_unit_price"
              :min="0"
              :precision="2"
              :controls="false"
              style="width: 100%"
              placeholder="请输入运费单价"
            />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="趟数（按趟）" v-if="editForm.freight_calc_scheme === 'by_trip'">
            <el-input-number
              v-model="editForm.freight_trip_count"
              :min="1"
              :precision="0"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
      </el-row>
      <el-divider content-position="left">亏吨扣减配置（独立于运价方式）</el-divider>
      <el-row :gutter="12">
        <el-col :span="8">
          <el-form-item label="实送重量kg（完单前可空）">
            <el-input-number
              v-model="editForm.actual_delivered_weight_kg"
              :min="0"
              :precision="2"
              :controls="false"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="允许亏吨kg">
            <el-input-number
              v-model="editForm.loss_allowance_kg"
              :min="0"
              :precision="2"
              :controls="false"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="亏吨扣减单价(元/吨)">
            <el-input-number
              v-model="editForm.loss_deduct_unit_price"
              :min="0"
              :precision="2"
              :controls="false"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
      </el-row>
    </el-form>
    <template #footer>
      <el-button @click="editDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="editing" @click="updatePrePlanOrder">保存修改</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="manualDispatchDialogVisible" title="预计划单手动派单" width="720px" destroy-on-close>
    <el-alert type="info" :closable="false" show-icon class="mb-12" title="仅可派发待调度/已排程的订单。若任务节点已到达或完成，后续将禁止修改。" />
    <el-table :data="selectedOrders" size="small" stripe class="mb-12">
      <el-table-column prop="order_no" label="订单号" min-width="170" />
      <el-table-column prop="client_name" label="客户" min-width="120" />
      <el-table-column prop="pickup_address" label="装货地" min-width="160" />
      <el-table-column prop="dropoff_address" label="卸货地" min-width="160" />
    </el-table>
    <el-form label-width="130px">
      <el-form-item label="派单车辆" required>
        <el-select v-model="manualDispatchForm.vehicle_id" :loading="loadingVehicles" placeholder="请选择车辆" style="width: 100%">
          <el-option
            v-for="vehicle in vehicles"
            :key="vehicle.id"
            :label="`${vehicle.plate_number}｜${vehicle.name}｜司机:${vehicle.driver?.name || '-'}(${vehicle.driver?.account || '-'})`"
            :value="vehicle.id"
          />
        </el-select>
      </el-form-item>
      <el-row :gutter="12">
        <el-col :span="8">
          <el-form-item label="预计里程(km)">
            <el-input-number v-model="manualDispatchForm.estimated_distance_km" :min="0" :precision="2" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="预计油耗(L)">
            <el-input-number v-model="manualDispatchForm.estimated_fuel_l" :min="0" :precision="2" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="预计时长(分钟)">
            <el-input-number v-model="manualDispatchForm.estimated_duration_min" :min="1" :precision="0" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
    </el-form>
    <template #footer>
      <el-button @click="manualDispatchDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="dispatching" @click="submitManualDispatch">确认派单</el-button>
    </template>
  </el-dialog>
</template>
