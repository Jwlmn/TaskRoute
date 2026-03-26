<template>
  <el-container class="portal-shell">
    <el-header class="portal-header">
      <div class="title">TaskRoute C端 客户服务门户</div>
      <el-button type="primary">新建配送需求</el-button>
    </el-header>

    <el-main class="portal-main">
      <el-row :gutter="16">
        <el-col :span="8">
          <el-card shadow="hover">
            <div class="label">今日待签收</div>
            <div class="value">19</div>
          </el-card>
        </el-col>
        <el-col :span="8">
          <el-card shadow="hover">
            <div class="label">运输中订单</div>
            <div class="value">42</div>
          </el-card>
        </el-col>
        <el-col :span="8">
          <el-card shadow="hover">
            <div class="label">异常预警</div>
            <div class="value danger">2</div>
          </el-card>
        </el-col>
      </el-row>

      <el-card class="mt-16" shadow="never">
        <template #header>
          <div class="table-title">订单追踪</div>
        </template>
        <el-empty description="二期将接入客户下单、查单与签收能力" />
      </el-card>
    </el-main>
  </el-container>
</template>

