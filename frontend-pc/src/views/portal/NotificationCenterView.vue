<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'

const loading = ref(false)
const pinningId = ref(null)
const messages = ref([])
const selectedIds = ref([])

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
    ElMessage.error(error?.response?.data?.message || '加载通知失败')
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
      <div class="table-header">
        <div class="card-title">通知中心</div>
        <div>
          <el-button class="mr-8" plain @click="markReadBatch">批量已读</el-button>
          <el-button plain @click="loadMessages">刷新</el-button>
        </div>
      </div>
    </template>

    <el-form inline class="mb-12">
      <el-form-item label="关键词">
        <el-input v-model="filterForm.keyword" clearable placeholder="标题/内容" style="width: 220px" />
      </el-form-item>
      <el-form-item label="已读状态">
        <el-select v-model="filterForm.read_status" style="width: 130px">
          <el-option label="全部" value="all" />
          <el-option label="未读" value="unread" />
          <el-option label="已读" value="read" />
        </el-select>
      </el-form-item>
      <el-form-item label="类型">
        <el-select v-model="filterForm.message_type" clearable style="width: 160px">
          <el-option label="审核通知" value="audit_notice" />
          <el-option label="审核催办" value="audit_reminder" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-checkbox v-model="filterForm.pinned_only">仅看置顶</el-checkbox>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="loadMessages">查询</el-button>
      </el-form-item>
    </el-form>

    <el-table :data="messages" stripe v-loading="loading" @selection-change="onSelectionChange">
      <el-table-column type="selection" width="50" />
      <el-table-column label="置顶" width="70">
        <template #default="{ row }">
          <el-tag v-if="row.is_pinned" type="warning">置顶</el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column prop="message_type" label="类型" min-width="120" />
      <el-table-column prop="title" label="标题" min-width="160" />
      <el-table-column prop="content" label="内容" min-width="260" />
      <el-table-column label="状态" min-width="90">
        <template #default="{ row }">
          <el-tag :type="row.read_at ? 'info' : 'danger'">{{ row.read_at ? '已读' : '未读' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="170" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" :disabled="!!row.read_at" @click="markRead(row.id)">已读</el-button>
          <el-button link type="warning" :loading="pinningId === row.id" @click="togglePin(row)">
            {{ row.is_pinned ? '取消置顶' : '置顶' }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>
</template>
