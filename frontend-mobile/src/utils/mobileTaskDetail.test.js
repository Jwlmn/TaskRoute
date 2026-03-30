import { describe, expect, it } from 'vitest'
import {
  buildUploadWarningMessage,
  canOperateTaskDetail,
  hasPendingTaskException,
  resolveTaskIdParam,
  shouldShowAcceptTaskTip,
} from './mobileTaskDetail'

describe('mobileTaskDetail utils', () => {
  it('能正确解析合法任务 id', () => {
    expect(resolveTaskIdParam('12')).toBe(12)
    expect(resolveTaskIdParam(8)).toBe(8)
  })

  it('会拒绝非法任务 id', () => {
    expect(resolveTaskIdParam('')).toBeNull()
    expect(resolveTaskIdParam('abc')).toBeNull()
    expect(resolveTaskIdParam('-1')).toBeNull()
    expect(resolveTaskIdParam('0')).toBeNull()
  })

  it('待处理异常任务不可操作且提示正确', () => {
    const detail = {
      status: 'in_progress',
      route_meta: {
        exception: {
          status: 'pending',
        },
      },
    }

    expect(hasPendingTaskException(detail)).toBe(true)
    expect(canOperateTaskDetail(detail)).toBe(false)
    expect(buildUploadWarningMessage(detail)).toBe('异常处理中，暂不可上传单据')
  })

  it('已接单和执行中任务在无异常时可操作', () => {
    expect(canOperateTaskDetail({ status: 'accepted' })).toBe(true)
    expect(canOperateTaskDetail({ status: 'in_progress' })).toBe(true)
    expect(canOperateTaskDetail({ status: 'completed' })).toBe(false)
  })

  it('待接单任务显示接单提示', () => {
    expect(shouldShowAcceptTaskTip({ status: 'assigned' })).toBe(true)
    expect(shouldShowAcceptTaskTip({ status: 'accepted' })).toBe(false)
  })
})
