<script setup>
import { computed } from 'vue'
import { getLabel, taskStatusLabelMap } from '../../utils/labels'
import {
  auditStatusLabelMap,
  formatDateTime,
  freightSchemeLabelMap,
  getFreightTemplateMeta,
  historyActionLabelMap,
} from '../../utils/prePlanOrder'

const props = defineProps({
  order: {
    type: Object,
    default: null,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  compareRows: {
    type: Array,
    default: () => [],
  },
  compareLoading: {
    type: Boolean,
    default: false,
  },
  showCompare: {
    type: Boolean,
    default: false,
  },
  showTemplateId: {
    type: Boolean,
    default: false,
  },
  showContactFields: {
    type: Boolean,
    default: false,
  },
  showExpectedTimes: {
    type: Boolean,
    default: false,
  },
  showFreightFields: {
    type: Boolean,
    default: false,
  },
})

const detailHistory = computed(() => {
  const list = props.order?.meta?.history
  return Array.isArray(list) ? [...list].reverse() : []
})
</script>

<template>
  <el-skeleton :loading="loading" animated :rows="6">
    <template #default>
      <el-descriptions v-if="order" :column="2" border size="small">
        <el-descriptions-item label="预计划单号">{{ order.order_no || '-' }}</el-descriptions-item>
        <el-descriptions-item label="客户">{{ order.client_name || '-' }}</el-descriptions-item>
        <el-descriptions-item label="货品分类">{{ order.cargo_category?.name || '-' }}</el-descriptions-item>
        <el-descriptions-item label="审核状态">{{ getLabel(auditStatusLabelMap, order.audit_status) }}</el-descriptions-item>
        <el-descriptions-item label="装货地">{{ order.pickup_address || '-' }}</el-descriptions-item>
        <el-descriptions-item label="卸货地">{{ order.dropoff_address || '-' }}</el-descriptions-item>
        <el-descriptions-item label="状态">{{ getLabel(taskStatusLabelMap, order.status) }}</el-descriptions-item>
        <el-descriptions-item label="提报人">
          {{ order.submitter?.name || order.submitter?.account || (order.submitter_id ? `#${order.submitter_id}` : '-') }}
        </el-descriptions-item>
        <el-descriptions-item label="审核人">
          {{ order.auditor?.name || order.auditor?.account || (order.audited_by ? `#${order.audited_by}` : '-') }}
        </el-descriptions-item>
        <el-descriptions-item label="审核时间">{{ formatDateTime(order.audited_at) }}</el-descriptions-item>
        <template v-if="showContactFields">
          <el-descriptions-item label="装货联系人">
            {{ order.pickup_contact_name || '-' }} / {{ order.pickup_contact_phone || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="收货联系人">
            {{ order.dropoff_contact_name || '-' }} / {{ order.dropoff_contact_phone || '-' }}
          </el-descriptions-item>
        </template>
        <template v-if="showExpectedTimes">
          <el-descriptions-item label="预计提货">{{ formatDateTime(order.expected_pickup_at) }}</el-descriptions-item>
          <el-descriptions-item label="预计送达">{{ formatDateTime(order.expected_delivery_at) }}</el-descriptions-item>
        </template>
        <template v-if="showFreightFields">
          <el-descriptions-item label="运价方式">{{ getLabel(freightSchemeLabelMap, order.freight_calc_scheme) }}</el-descriptions-item>
          <el-descriptions-item label="运价单价">{{ order.freight_unit_price ?? '-' }}</el-descriptions-item>
        </template>
        <el-descriptions-item label="命中模板">
          {{ getFreightTemplateMeta(order)?.name || '未命中模板' }}
        </el-descriptions-item>
        <el-descriptions-item v-if="showTemplateId" label="模板ID">
          {{ getFreightTemplateMeta(order)?.id || '-' }}
        </el-descriptions-item>
        <el-descriptions-item label="审核备注">{{ order.audit_remark || '-' }}</el-descriptions-item>
      </el-descriptions>

      <template v-if="showCompare && order?.audit_status === 'rejected'">
        <el-divider content-position="left">驳回后版本对比</el-divider>
        <el-table :data="compareRows" size="small" stripe v-loading="compareLoading">
          <el-table-column prop="field" label="字段" min-width="180" />
          <el-table-column prop="before" label="驳回时值" min-width="220" />
          <el-table-column prop="after" label="当前值" min-width="220" />
        </el-table>
        <el-empty
          v-if="!compareLoading && compareRows.length === 0"
          description="暂无差异（可能尚未修改关键字段）"
        />
      </template>

      <el-divider content-position="left">操作轨迹</el-divider>
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
</template>
