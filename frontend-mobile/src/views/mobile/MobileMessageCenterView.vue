<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'

const loading = ref(false)
const messages = ref([])
const selectedIds = ref([])
const pinningId = ref(null)

const filterForm = reactive({
  keyword: '',
  read_status: 'all',
  message_type: '',
  pinned_only: false,
})

const loadMessages = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/message/list', {
      keyword: filterForm.keyword || undefined,
      read_status: filterForm.read_status || 'all',
      message_type: filterForm.message_type || undefined,
      pinned_only: filterForm.pinned_only || false,
    })
    messages.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载消息失败')
  } finally {
    loading.value = false
  }
}

const onSelectionChange = (rows) => {
  selectedIds.value = rows.map((item) => item.id)
}

const markRead = async (id) => {
  try {
    await api.post('/message/read', { id })
    await loadMessages()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '标记已读失败')
  }
}

const markReadBatch = async () => {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选消息')
    return
  }
  try {
    await api.post('/message/read-batch', { ids: selectedIds.value })
    ElMessage.success('批量已读成功')
    await loadMessages()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '批量已读失败')
  }
}

const togglePin = async (row) => {
  pinningId.value = row.id
  try {
    await api.post('/message/pin', {
      id: row.id,
      is_pinned: !row.is_pinned,
    })
    await loadMessages()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '置顶操作失败')
  } finally {
    pinningId.value = null
  }
}

onMounted(loadMessages)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="mobile-section-title">消息中心</div>
    </template>

    <div class="mobile-message-toolbar">
      <el-input
        v-model.trim="filterForm.keyword"
        clearable
        size="small"
        placeholder="搜索标题或内容"
      />
      <el-select v-model="filterForm.read_status" size="small">
        <el-option label="全部" value="all" />
        <el-option label="未读" value="unread" />
        <el-option label="已读" value="read" />
      </el-select>
      <div class="mobile-message-actions">
        <el-checkbox v-model="filterForm.pinned_only">仅看置顶</el-checkbox>
        <el-button size="small" type="primary" @click="loadMessages">查询</el-button>
        <el-button size="small" plain @click="markReadBatch">批量已读</el-button>
      </div>
    </div>

    <el-table
      :data="messages"
      stripe
      v-loading="loading"
      class="mobile-message-table"
      @selection-change="onSelectionChange"
    >
      <el-table-column type="selection" width="46" />
      <el-table-column label="标题" min-width="180">
        <template #default="{ row }">
          <div class="mobile-message-title">
            <el-tag v-if="row.is_pinned" size="small" type="warning">置顶</el-tag>
            <el-tag v-if="!row.read_at" size="small" type="danger">未读</el-tag>
            <span>{{ row.title || '-' }}</span>
          </div>
          <div class="mobile-message-content">{{ row.content || '-' }}</div>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="130" fixed="right">
        <template #default="{ row }">
          <div class="mobile-message-row-actions">
            <el-button link type="primary" :disabled="!!row.read_at" @click="markRead(row.id)">已读</el-button>
            <el-button link type="warning" :loading="pinningId === row.id" @click="togglePin(row)">
              {{ row.is_pinned ? '取消置顶' : '置顶' }}
            </el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>
  </el-card>
</template>
