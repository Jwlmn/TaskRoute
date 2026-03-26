<script setup>
const upcomingTasks = [
  {
    taskNo: 'DT-20260326-A1B2C3',
    vehicle: '沪A12345',
    driver: '张师傅',
    mode: '单车多订单',
    status: '待确认',
  },
  {
    taskNo: 'DT-20260326-D4E5F6',
    vehicle: '沪B66889',
    driver: '李师傅',
    mode: '多车单订单',
    status: '执行中',
  },
]
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-title">调度工作台</div>
    </template>
    <el-table :data="upcomingTasks" stripe>
      <el-table-column prop="taskNo" label="任务编号" min-width="180" />
      <el-table-column prop="vehicle" label="车辆" min-width="110" />
      <el-table-column prop="driver" label="司机" min-width="110" />
      <el-table-column prop="mode" label="派单模式" min-width="120" />
      <el-table-column prop="status" label="状态" min-width="100" />
    </el-table>
  </el-card>
</template>

